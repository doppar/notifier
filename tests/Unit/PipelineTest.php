<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;
use Doppar\Queue\Facades\Queue;

class PipelineTest extends NotifierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireQueue();
    }

    public function testEachChannelIsItsOwnJob(): void
    {
        $id = $this->user(1)->notify(new ConfigurableNotification(['database', 'mail', 'slack']))->send();

        $this->assertSame(3, Queue::size('notifications'));
        $this->assertSame(3, (int) $this->value('SELECT count(*) FROM queue_jobs'));
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    public function testTheDeliveriesOfOneSendShareTheReturnedId(): void
    {
        $id = $this->user(1)->notify(new ConfigurableNotification(['database', 'mail', 'webhook']))->send();
        $this->drain();

        $rows = $this->rows("SELECT notification_id, channel, status FROM notification_deliveries ORDER BY channel");
        $this->assertSame(['database', 'mail', 'webhook'], array_column($rows, 'channel'));
        $this->assertSame([$id], array_values(array_unique(array_column($rows, 'notification_id'))));
        $this->assertSame(['sent'], array_values(array_unique(array_column($rows, 'status'))));
    }

    public function testEveryDispatchGetsADifferentId(): void
    {
        $a = $this->user(1)->notify(new ConfigurableNotification(['database']))->send();
        $b = $this->user(1)->notify(new ConfigurableNotification(['database']))->send();

        $this->assertNotSame($a, $b);
    }

    public function testNoChannelsMeansNothingIsQueued(): void
    {
        $this->assertNull($this->user(1)->notify(new ConfigurableNotification([]))->send());
        $this->assertNull($this->user(1)->notify(new ConfigurableNotification(['database']))->via([])->send());
        $this->assertSame(0, Queue::size('notifications'));
    }

    public function testRepeatedChannelsAreQueuedOnce(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['slack', 'slack', 'slack']))->send();

        $this->assertSame(1, Queue::size('notifications'));
    }

    public function testSendingTwiceFromTheSameBuilderQueuesOnce(): void
    {
        $pipeline = $this->user(1)->notify(new ConfigurableNotification(['database']));
        $pipeline->send();

        $this->assertNull($pipeline->send());
        $this->assertSame(1, Queue::size('notifications'));
    }

    public function testANotificationThatIsNeverSentExplicitlyGoesOutWhenTheBuilderIsDropped(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database']));

        $this->assertSame(1, Queue::size('notifications'));
    }

    public function testADelayDelaysEveryChannel(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack']))->delay(300)->send();

        foreach ($this->rows('SELECT available_at FROM queue_jobs') as $row) {
            $this->assertEqualsWithDelta(time() + 300, (int) $row['available_at'], 5);
        }
        $this->assertSame(0, $this->drain(), 'nothing is ready yet');
    }

    public function testTheNotificationsOwnDelayIsUsedWhenNoneIsGiven(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database'], ['delay' => 600]))->send();

        $this->assertEqualsWithDelta(time() + 600, (int) $this->value('SELECT available_at FROM queue_jobs'), 5);
    }

    public function testViaChoosesTheChannelsAndBeatsTheNotification(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail', 'slack']))->via(['webhook'])->send();
        $this->drain();

        $this->assertSame(['webhook'], array_column($this->rows('SELECT channel FROM notification_deliveries'), 'channel'));
        $this->assertCount(1, $this->http);
        $this->assertSame([], $this->mails);
    }

    public function testThePreferencesOfTheNotifiableFilterTheNotificationsOwnChannels(): void
    {
        $this->pdo->exec("UPDATE users SET muted = 'slack,mail' WHERE id = 1");

        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail', 'slack', 'webhook']))->send();
        $this->drain();

        $this->assertSame(['database', 'webhook'], array_column($this->rows('SELECT channel FROM notification_deliveries ORDER BY channel'), 'channel'));
        $this->assertSame([], $this->mails, 'the person switched mail off');
    }

    public function testExplicitViaIsNotFilteredByPreferences(): void
    {
        $this->pdo->exec("UPDATE users SET muted = 'mail' WHERE id = 1");

        $this->user(1)->notify(new ConfigurableNotification(['database']))->via(['mail'])->send();
        $this->drain();

        $this->assertCount(1, $this->mails, 'asking for a channel by name is a decision, not a default');
    }

    public function testAnEverythingMutedPersonReceivesNothing(): void
    {
        $this->pdo->exec("UPDATE users SET muted = 'database,mail' WHERE id = 1");

        $this->assertNull($this->user(1)->notify(new ConfigurableNotification(['database', 'mail']))->send());
        $this->assertSame(0, Queue::size('notifications'));
    }

    public function testNotifyNowDeliversAtOnceWithoutTheQueue(): void
    {
        $this->user(1)->notifyNow(new ConfigurableNotification(['database', 'mail']));

        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'));
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertCount(1, $this->mails);
    }

    public function testNotifyNowStillTriesEveryChannelWhenOneFailsAndThenThrows(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];

        try {
            $this->user(1)->notifyNow(new ConfigurableNotification(['slack', 'database', 'mail']));
            $this->fail('The slack failure must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Slack notification failed', $e->getMessage());
        }

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'), 'database was still delivered');
        $this->assertCount(1, $this->mails, 'and so was mail');
    }

    public function testAnUnknownChannelIsRejectedWhenItRuns(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['carrier-pigeon']))->send();
        $this->drain();

        $this->assertSame('failed', $this->value("SELECT status FROM notification_deliveries WHERE channel = 'carrier-pigeon'"));
        $this->assertStringContainsString('not supported', (string) $this->value("SELECT error FROM notification_deliveries WHERE channel = 'carrier-pigeon'"));
    }
}
