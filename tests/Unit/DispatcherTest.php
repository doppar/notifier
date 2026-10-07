<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\NotificationDispatcher;
use Doppar\Notifier\Support\DeliveryLog;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

/**
 * What the dispatcher job does with a delivery: isolation between channels, retries that do not repeat what
 * already worked, skipping, throttling and the delivery log.
 */
class DispatcherTest extends NotifierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireQueue();
    }

    private function retry(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->releaseQueuedJobs();
            $this->drain();
        }
    }

    public function testAFailingChannelDoesNotRepeatTheOthers(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];

        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack']))->send();
        $this->retry(3);

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'), 'one database row, not one per retry');
        $this->assertCount(3, $this->http, 'slack was tried 3 times');
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM failed_jobs'), 'only the slack job failed');
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'));
    }

    public function testTheLogShowsWhatHappenedOnEachChannel(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];

        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack']))->send();
        $this->retry(3);

        $rows = array_column($this->rows('SELECT channel, status, attempts, error FROM notification_deliveries'), null, 'channel');
        $this->assertSame(['sent', 1, null], [$rows['database']['status'], (int) $rows['database']['attempts'], $rows['database']['error']]);
        $this->assertSame('failed', $rows['slack']['status']);
        $this->assertSame(3, (int) $rows['slack']['attempts']);
        $this->assertStringContainsString('HTTP 500', $rows['slack']['error']);
    }

    public function testARetryThatSucceedsClearsTheError(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $this->user(1)->notify(new ConfigurableNotification(['slack']))->send();
        $this->retry(1);
        $this->assertSame('failed', $this->value('SELECT status FROM notification_deliveries'));

        $this->httpAnswer = ['status' => 200, 'body' => 'ok', 'error' => ''];
        $this->retry(1);

        $row = $this->rows('SELECT status, attempts, error, sent_at FROM notification_deliveries')[0];
        $this->assertSame('sent', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertNull($row['error']);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM failed_jobs'));
    }

    public function testAJobWithSeveralChannelsTriesAllOfThemAndThrowsAtTheEnd(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(), ['slack', 'database', 'mail']);

        try {
            $job->handle();
            $this->fail('The slack failure must be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Slack', $e->getMessage());
        }

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'), 'database ran after the failed slack');
        $this->assertCount(1, $this->mails);
    }

    public function testAnOldQueuedJobWithSeveralChannelsDoesNotDuplicateOnRetry(): void
    {
        // Jobs queued by an earlier version carry all the channels in one job.
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        $id = 'aaaaaaaa-0000-4000-8000-000000000001';

        foreach ([1, 2, 3] as $attempt) {
            $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(), ['database', 'slack'], $id);
            try {
                $job->handle();
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertCount(3, $this->http);
    }

    public function testAJobQueuedBeforeNotificationIdsExistedStillRuns(): void
    {
        $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(), ['database']);
        $payload = serialize($job);
        $revived = unserialize($payload);
        $revived->notificationId = null; // as if the job was written by an older version
        $revived->handle();

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testShouldSendFalseSkipsEveryChannelAndSaysSo(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack'], ['shouldSend' => false]))->send();
        $this->drain();

        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertSame([], $this->http);
        $this->assertSame(['skipped'], array_values(array_unique(array_column($this->rows('SELECT status FROM notification_deliveries'), 'status'))));
    }

    public function testShouldSendViaSkipsOnlyThatChannel(): void
    {
        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack'], ['skipChannels' => ['slack']]))->send();
        $this->drain();

        $rows = array_column($this->rows('SELECT channel, status FROM notification_deliveries'), 'status', 'channel');
        $this->assertSame(['database' => 'sent', 'slack' => 'skipped'], $rows);
        $this->assertSame([], $this->http);
    }

    public function testThrottleLimitsHowOftenTheSameNotificationReachesTheSameNotifiable(): void
    {
        $options = ['throttle' => ['max' => 2, 'per' => 3600]];

        for ($i = 0; $i < 4; $i++) {
            $this->user(1)->notify(new ConfigurableNotification(['database'], $options))->send();
        }
        $this->user(2)->notify(new ConfigurableNotification(['database'], $options))->send();
        $this->drain();

        $this->assertSame(3, (int) $this->value('SELECT count(*) FROM notifications'), 'two for Ann, one for Bob');
        $this->assertSame(2, (int) $this->value("SELECT count(*) FROM notification_deliveries WHERE status = 'throttled'"));
        $this->assertSame(2, (int) $this->value("SELECT count(*) FROM notifications WHERE notifiable_id = 1"));
    }

    public function testThrottleOnlyCountsThePeriod(): void
    {
        $options = ['throttle' => ['max' => 1, 'per' => 60]];
        $this->user(1)->notify(new ConfigurableNotification(['database'], $options))->send();
        $this->drain();
        $this->pdo->exec("UPDATE notification_deliveries SET sent_at = '" . date('Y-m-d H:i:s', time() - 120) . "'");

        $this->user(1)->notify(new ConfigurableNotification(['database'], $options))->send();
        $this->drain();

        $this->assertSame(2, (int) $this->value('SELECT count(*) FROM notifications'), 'the first delivery is older than the period');
    }

    public function testThrottleIsPerChannel(): void
    {
        $options = ['throttle' => ['max' => 1, 'per' => 3600]];

        for ($i = 0; $i < 2; $i++) {
            $this->user(1)->notify(new ConfigurableNotification(['database', 'mail'], $options))->send();
        }
        $this->drain();

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertCount(1, $this->mails);
    }

    public function testWithoutTheDeliveryTableEverythingStillWorksButThereIsNoThrottle(): void
    {
        $this->pdo->exec('DROP TABLE notification_deliveries');
        DeliveryLog::reset();

        for ($i = 0; $i < 3; $i++) {
            $this->user(1)->notify(new ConfigurableNotification(['database'], ['throttle' => ['max' => 1, 'per' => 3600]]))->send();
        }
        $this->drain();

        $this->assertSame(3, (int) $this->value('SELECT count(*) FROM notifications'), 'delivery is not blocked by a missing log');
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM failed_jobs'));
    }

    public function testWithoutTheDeliveryTableAFailingChannelIsStillRetriedAndFails(): void
    {
        $this->pdo->exec('DROP TABLE notification_deliveries');
        DeliveryLog::reset();
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];

        $this->user(1)->notify(new ConfigurableNotification(['slack']))->send();
        $this->retry(3);

        $this->assertCount(3, $this->http);
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM failed_jobs'));
    }

    public function testTheDispatcherKeepsItsQueueSettings(): void
    {
        $job = new NotificationDispatcher($this->user(1), new ConfigurableNotification(), ['database']);

        // The settings come from the #[Queueable] attribute and are applied when the job is dispatched.
        (function () {
            $this->applyQueueableAttributes();
        })->call($job);

        $this->assertSame(3, $job->tries());
        $this->assertSame(60, $job->retryAfter());
        $this->assertSame('notifications', $job->queue());
    }

    public function testTheNotifiableComesBackFromTheQueueIntact(): void
    {
        $this->user(2)->notify(new ConfigurableNotification(['database']))->send();
        $this->drain();

        $this->assertSame(2, (int) $this->value('SELECT notifiable_id FROM notifications'));
        $this->assertSame('Bob', $this->user(2)->name);
    }
}
