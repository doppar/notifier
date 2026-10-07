<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Supports\Facades\Notification;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class EventsTest extends NotifierTestCase
{
    /**
     * Collect what the listeners are told
     *
     * @var array<int, string>
     */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
    }

    public function testSendingThenSentAreFiredForEachChannelInOrder(): void
    {
        Notification::sending(function ($notifiable, $notification, string $channel): void {
            $this->log[] = "sending:$channel:" . $notifiable->id;
        });
        Notification::sent(function ($notifiable, $notification, string $channel): void {
            $this->log[] = "sent:$channel";
        });

        $this->user(2)->notifyNow(new ConfigurableNotification(['database', 'mail']));

        $this->assertSame(['sending:database:2', 'sent:database', 'sending:mail:2', 'sent:mail'], $this->log);
    }

    public function testTheListenersReceiveTheNotificationAndTheNotifiable(): void
    {
        $seen = null;
        Notification::sent(function ($notifiable, $notification, string $channel) use (&$seen): void {
            $seen = [get_class($notifiable), get_class($notification), $channel];
        });

        $this->user(1)->notifyNow(new ConfigurableNotification(['webhook']));

        $this->assertSame([\Doppar\Notifier\Tests\Mock\Models\TestUser::class, ConfigurableNotification::class, 'webhook'], $seen);
    }

    public function testASendingListenerThatReturnsFalseCancelsThatDelivery(): void
    {
        Notification::sending(fn ($notifiable, $notification, string $channel): bool => $channel !== 'mail');
        Notification::sent(function ($notifiable, $notification, string $channel): void {
            $this->log[] = "sent:$channel";
        });

        $this->user(1)->notifyNow(new ConfigurableNotification(['database', 'mail']));

        $this->assertSame(['sent:database'], $this->log);
        $this->assertSame([], $this->mails, 'the cancelled delivery never happened');
        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
        if (static::queueAvailable()) {
            $this->assertSame('cancelled', $this->value("SELECT status FROM notification_deliveries WHERE channel = 'mail'"));
        }
    }

    public function testOneListenerSayingNoIsEnough(): void
    {
        Notification::sending(fn (): bool => true);
        Notification::sending(fn (): bool => false);
        Notification::sending(fn () => null);

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testAListenerReturningNothingDoesNotCancel(): void
    {
        Notification::sending(function (): void {
        });

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testFailedIsFiredWithTheException(): void
    {
        $this->httpAnswer = ['status' => 500, 'body' => 'down', 'error' => ''];
        Notification::failed(function ($notifiable, $notification, string $channel, \Throwable $e): void {
            $this->log[] = "failed:$channel:" . $e->getMessage();
        });
        Notification::sent(function ($n, $x, string $channel): void {
            $this->log[] = "sent:$channel";
        });

        try {
            $this->user(1)->notifyNow(new ConfigurableNotification(['slack', 'database']));
        } catch (\RuntimeException) {
        }

        $this->assertCount(2, $this->log);
        $this->assertStringStartsWith('failed:slack:Slack notification failed (HTTP 500)', $this->log[0]);
        $this->assertSame('sent:database', $this->log[1], 'the other channel still went out');
    }

    public function testABrokenListenerNeverStopsADelivery(): void
    {
        Notification::sending(function (): void {
            throw new \RuntimeException('listener bug');
        });
        Notification::sent(function (): void {
            throw new \RuntimeException('another listener bug');
        });
        Notification::sent(function ($n, $x, string $channel): void {
            $this->log[] = "sent:$channel";
        });

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'), 'delivered anyway');
        $this->assertSame(['sent:database'], $this->log, 'later listeners still run');
    }

    public function testASkippedChannelFiresNothing(): void
    {
        Notification::sending(function ($n, $x, string $channel): void {
            $this->log[] = "sending:$channel";
        });

        $this->user(1)->notifyNow(new ConfigurableNotification(['database', 'slack'], ['skipChannels' => ['slack']]));

        $this->assertSame(['sending:database'], $this->log);
    }

    public function testFlushRemovesTheListeners(): void
    {
        Notification::sent(function (): void {
            $this->log[] = 'sent';
        });
        Notification::flushListeners();

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame([], $this->log);
    }

    public function testEventsFireWhenAQueuedJobRuns(): void
    {
        $this->requireQueue();
        Notification::sent(function ($n, $x, string $channel): void {
            $this->log[] = "sent:$channel";
        });

        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();
        $this->assertSame([], $this->log, 'nothing yet: it is queued');
        $this->drain();

        $this->assertSame(['sent:database'], $this->log);
    }
}
