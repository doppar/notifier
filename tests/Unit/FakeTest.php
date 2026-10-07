<?php

namespace Doppar\Notifier\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use Doppar\Notifier\Supports\Facades\Notification;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Mock\Notifications\TestEmailNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class FakeTest extends NotifierTestCase
{
    /**
     * Assert that a callback fails an assertion
     *
     * @param callable $callback
     * @param string $contains
     * @return void
     */
    private function assertFails(callable $callback, string $contains = ''): void
    {
        try {
            $callback();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }

        $this->fail('The assertion should have failed.');
    }

    public function testNothingIsDeliveredWhileFaking(): void
    {
        $fake = Notification::fake();

        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail']))->send();
        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));
        Notification::to($this->user(2))->send(new ConfigurableNotification(['slack']));

        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM notifications'));
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM queue_jobs'));
        $this->assertSame([], $this->mails);
        $this->assertSame([], $this->http);
        $fake->assertCount(3);
    }

    public function testSendReturnsAnIdLikeTheRealThing(): void
    {
        Notification::fake();

        $this->assertIsString($this->user(1)->notify(new ConfigurableNotification(['database']))->send());
    }

    public function testAssertSentToByClassAndByCallback(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new TestEmailNotification('Hi', 'There'))->send();

        $fake->assertSentTo($this->user(1), TestEmailNotification::class);
        $fake->assertSentTo($this->user(1), fn ($notification, $channels, $notifiable) => $notification->subject === 'Hi' && $channels === ['database'] && $notifiable->id === 1);
        $fake->assertNotSentTo($this->user(2), TestEmailNotification::class);
        $fake->assertNotSentTo($this->user(1), ConfigurableNotification::class);
        $fake->assertNotSentTo($this->user(1), fn ($notification) => $notification->subject === 'Other');
    }

    public function testTheNotifiableIsRecognisedByTypeAndKeyNotByInstance(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();

        $fake->assertSentTo($this->user(1), ConfigurableNotification::class);
        $this->assertCount(1, $fake->sent($this->user(1)));
        $this->assertCount(0, $fake->sent($this->user(2)));
    }

    public function testAssertSentToFailsWithAMessage(): void
    {
        $fake = Notification::fake();

        $this->assertFails(fn () => $fake->assertSentTo($this->user(1), ConfigurableNotification::class), 'was not sent');
        $this->assertFails(fn () => $fake->assertSentTo($this->user(1), fn () => true), 'matching');
    }

    public function testNumberOfTimes(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();
        $this->user(1)->notify(new ConfigurableNotification(['mail']))->send();
        $this->user(2)->notify(new ConfigurableNotification(['database']))->send();

        $fake->assertSentTo($this->user(1), ConfigurableNotification::class, 2);
        $fake->assertSentTimes(ConfigurableNotification::class, 3);
        $this->assertFails(fn () => $fake->assertSentTo($this->user(1), ConfigurableNotification::class, 1), 'sent 2 time');
        $this->assertFails(fn () => $fake->assertSentTimes(ConfigurableNotification::class, 5), 'sent 3 time');
    }

    public function testAssertSentVia(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database', 'slack']))->send();

        $fake->assertSentVia($this->user(1), ConfigurableNotification::class, 'slack');
        $this->assertFails(fn () => $fake->assertSentVia($this->user(1), ConfigurableNotification::class, 'mail'), 'mail');
    }

    public function testChannelsChosenWithViaAreRecorded(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->via(['mail', 'webhook'])->send();

        $this->assertSame(['mail', 'webhook'], $fake->sent()[0]['channels']);
    }

    public function testThePreferencesStillApplyToTheRecordedChannels(): void
    {
        $fake = Notification::fake();
        $this->pdo->exec("UPDATE users SET muted = 'mail' WHERE id = 1");
        $this->user(1)->notify(new ConfigurableNotification(['database', 'mail']))->send();

        $fake->assertSentVia($this->user(1), ConfigurableNotification::class, 'database');
        $this->assertFails(fn () => $fake->assertSentVia($this->user(1), ConfigurableNotification::class, 'mail'));
    }

    public function testNothingSentAndCount(): void
    {
        $fake = Notification::fake();
        $fake->assertNothingSent();
        $fake->assertCount(0);

        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();

        $this->assertFails(fn () => $fake->assertNothingSent(), '1 were sent');
        $this->assertFails(fn () => $fake->assertCount(2), 'Expected 2');
    }

    public function testNotificationsWithNoChannelsAreNotRecorded(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification([]))->send();

        $fake->assertNothingSent();
    }

    public function testDelayAndImmediateAreRecorded(): void
    {
        $fake = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->delay(90)->send();
        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        [$delayed, $now] = $fake->sent();
        $this->assertSame([90, false], [$delayed['delay'], $delayed['immediate']]);
        $this->assertSame([0, true], [$now['delay'], $now['immediate']]);
    }

    public function testBulkAndQueryBuildersAreCaptured(): void
    {
        $fake = Notification::fake();

        Notification::toMany([$this->user(1), $this->user(2)])->send(new ConfigurableNotification(['database']));
        Notification::toAll(TestUser::class)->where('id', 3)->send(new ConfigurableNotification(['database']));
        Notification::schedule(new ConfigurableNotification(['database']))->to($this->user(1))->after(60);

        $fake->assertCount(4);
        $fake->assertSentTo($this->user(3), ConfigurableNotification::class);
        $this->assertSame(0, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testUnfakeSendsForReal(): void
    {
        Notification::fake();
        Notification::unfake();

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame(1, (int) $this->value('SELECT count(*) FROM notifications'));
    }

    public function testAFreshFakeStartsEmpty(): void
    {
        $first = Notification::fake();
        $this->user(1)->notify(new ConfigurableNotification(['database']))->send();

        $second = Notification::fake();

        $second->assertNothingSent();
        $this->assertNotSame($first, $second);
    }

    public function testNoEventsAreFiredWhileFaking(): void
    {
        $fired = [];
        Notification::sending(function () use (&$fired): void {
            $fired[] = 'sending';
        });
        Notification::fake();

        $this->user(1)->notifyNow(new ConfigurableNotification(['database']));

        $this->assertSame([], $fired);
    }
}
