<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\NotificationDispatcher;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;
use Doppar\Queue\Facades\Queue;

class QueueFeaturesTest extends NotifierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireQueueFeatures();
    }

    public function testAHigherPriorityNotificationIsDeliveredFirst(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database'], ['priority' => 0]))->send();
        $this->user(2)->notify(new ConfigurableNotification(['database'], ['priority' => 50]))->send();
        $this->user(3)->notify(new ConfigurableNotification(['database'], ['priority' => -10]))->send();

        $this->assertSame([50, 0, -10], array_map('intval', array_column($this->rows('SELECT priority FROM queue_jobs ORDER BY priority DESC'), 'priority')));

        $worker = $this->worker();
        $order = [];
        for ($i = 0; $i < 3; $i++) {
            $worker->runNextJob('notifications');
            $order[] = (int) $this->value('SELECT notifiable_id FROM notifications ORDER BY id DESC LIMIT 1');
        }

        $this->assertSame([2, 1, 3], $order);
    }

    public function testThePriorityIsKeptWithinTheLimits(): void
    {
        $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(['database'], ['priority' => 9999]), ['database']);
        $this->assertSame(100, $job->priority());

        $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(['database'], ['priority' => -9999]), ['database']);
        $this->assertSame(-100, $job->priority());
    }

    public function testAUniqueNotificationIsQueuedOnlyOnceWhileItWaits(): void
    {
        $notification = fn () => new ConfigurableNotification(['database'], ['uniqueId' => 'order-42']);

        $first = $this->user(1)->notify($notification())->send();
        $second = $this->user(1)->notify($notification())->send();

        $this->assertIsString($first);
        $this->assertIsString($second, 'the send still reports an id, the duplicate job was simply not queued');
        $this->assertSame(1, Queue::size('notifications'));

        $this->user(2)->notify($notification())->send();
        $this->assertSame(2, Queue::size('notifications'), 'another person is not a duplicate');

        $this->drain();
        $this->user(1)->notify($notification())->send();
        $this->assertSame(1, Queue::size('notifications'), 'once delivered, it can be queued again');
    }

    public function testUniqueIsPerChannel(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail'], ['uniqueId' => 'x']))->send();

        $this->assertSame(2, Queue::size('notifications'), 'database and mail are separate jobs, so both are queued');
    }

    public function testWithoutAUniqueIdDuplicatesAreAllowed(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();

        $this->assertSame(2, Queue::size('notifications'));
    }

    public function testTheBackoffOfTheNotificationSetsTheRetryDelay(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $this->user(1)->notify(new ConfigurableNotification(['slack'], ['backoff' => [5, 30, 120]]))->send();

        $this->drain();
        $this->assertEqualsWithDelta(time() + 5, (int) $this->value('SELECT available_at FROM queue_jobs'), 5, 'first retry after 5 seconds');

        $this->releaseQueuedJobs();
        $this->drain();
        $this->assertEqualsWithDelta(time() + 30, (int) $this->value('SELECT available_at FROM queue_jobs'), 5, 'second retry after 30 seconds');
    }

    public function testWithoutABackoffTheDispatchersRetryAfterApplies(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $this->user(1)->notify(new ConfigurableNotification(['slack']))->send();

        $this->drain();

        $this->assertEqualsWithDelta(time() + 60, (int) $this->value('SELECT available_at FROM queue_jobs'), 5);
    }

    public function testANotificationCanChooseItsQueueConnection(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database'], ['connection' => 'memory']))->send();

        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'), 'not in the database queue');
        $this->assertSame(1, Queue::size('notifications', 'memory'));
        $this->assertSame(0, Queue::size('notifications'));
    }
}
