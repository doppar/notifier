<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Facades\Queue;

class RedisQueueTest extends NotifierTestCase
{
    private ?object $redis = null;

    private string $prefix = '';

    private int $now = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireQueueFeatures();

        if (!class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('predis/predis is not installed.');
        }

        $url = getenv('QUEUE_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379';
        $this->redis = new \Predis\Client($url, ['parameters' => ['database' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15)]]);

        try {
            $this->redis->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }

        $this->prefix = '{ntest_' . bin2hex(random_bytes(6)) . '}';
        $this->now = time();
        $this->queue = new QueueManager([
            'default' => 'redis',
            'connections' => [
                'redis' => [
                    'driver' => 'redis',
                    'connection' => $url,
                    'prefix' => $this->prefix,
                    'lease' => 90,
                    'options' => ['parameters' => ['database' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15)]],
                ],
            ],
        ], fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        if ($this->redis !== null && $this->prefix !== '') {
            $keys = $this->redis->keys($this->prefix . '*');

            if ($keys !== []) {
                $this->redis->del($keys);
            }
        }

        parent::tearDown();
    }

    /**
     * Let the clock run past the retry delays and work off what is ready
     *
     * @param int $seconds
     * @return int
     */
    private function later(int $seconds = 61): int
    {
        $this->now += $seconds;

        return $this->drain();
    }

    public function testEachChannelIsOneJobOnRedis(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail', 'webhook']))->send();

        $this->assertSame(3, Queue::size('notifications'));
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'), 'nothing went to the database queue');

        $this->assertSame(3, $this->drain());
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertCount(1, $this->mails);
        $this->assertCount(1, $this->http);
        $this->assertSame(0, Queue::size('notifications'));
    }

    public function testAFailingChannelIsRetriedAloneAndThenFails(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack']))->send();

        $this->drain();
        $this->assertSame(1, Queue::size('notifications'), 'only the slack job is waiting for its retry');

        $this->later();
        $this->later();

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'), 'one database row');
        $this->assertCount(3, $this->http);
        $this->assertSame(1, Queue::connection('redis')->countFailed());
        $this->assertSame(0, Queue::size('notifications'));
    }

    public function testPriorityOrdersTheDeliveries(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database'], ['priority' => 0]))->send();
        $this->user(2)->notify(new ConfigurableNotification(['database'], ['priority' => 80]))->send();

        $this->drain();

        $this->assertSame([2, 1], array_map('intval', array_column($this->rows('SELECT notifiable_id FROM notifications ORDER BY id'), 'notifiable_id')));
    }

    public function testAUniqueNotificationIsQueuedOnce(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->user(1)->notify(new ConfigurableNotification(['database'], ['uniqueId' => 'same']))->send();
        }

        $this->assertSame(1, Queue::size('notifications'));
    }

    public function testADelayedNotificationWaitsForTheClock(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database']))->delay(300)->send();

        $this->assertSame(0, $this->drain());
        $this->assertSame(0, $this->later(100), 'still early');
        $this->assertSame(1, $this->later(250), 'now it is due');
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
    }
}
