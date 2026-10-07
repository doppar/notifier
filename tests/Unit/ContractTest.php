<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\NotificationManager;
use Doppar\Notifier\Support\Id;
use Doppar\Notifier\Support\Notifiables;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Mock\Notifications\TestEmailNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class ContractTest extends NotifierTestCase
{
    public function testTheDefaultsOfANotification(): void
    {
        $n = new TestEmailNotification('S', 'M');

        $this->assertTrue($n->shouldSend(null));
        $this->assertTrue($n->shouldSendVia(null, 'mail'));
        $this->assertSame(0, $n->deliveryDelay());
        $this->assertSame(0, $n->priority());
        $this->assertNull($n->uniqueId(null));
        $this->assertNull($n->backoff());
        $this->assertNull($n->onConnection());
        $this->assertNull($n->throttle());
        $this->assertSame([], (new class extends Notification {
            public function channels($notifiable): array
            {
                return [];
            }

            public function content($notifiable): array
            {
                return [];
            }
        })->metadata());
    }

    public function testContentForFallsBackToContentAndRoutesByName(): void
    {
        $n = new TestEmailNotification('Subject', 'Message');

        $this->assertSame(['subject' => 'Subject', 'message' => 'Message'], $n->contentFor('slack', null));
        $this->assertSame($n->content(null), $n->contentFor('', null), 'an empty channel name never calls a method called "to"');
    }

    public function testAToMethodThatReturnsNothingUsefulGivesAnEmptyContent(): void
    {
        $n = new class extends Notification {
            public function channels($notifiable): array
            {
                return ['mail'];
            }

            public function content($notifiable): array
            {
                return ['shared' => true];
            }

            public function toMail($notifiable)
            {
                return null;
            }
        };

        $this->assertSame([], $n->contentFor('mail', null));
    }

    public function testUuidsAreVersion4AndDifferent(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $id = Id::uuid();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
            $seen[$id] = true;
        }

        $this->assertCount(200, $seen);
    }

    public function testNotifiableKeysAndTypes(): void
    {
        $this->assertEquals(1, Notifiables::key($this->user(1)), 'a database key may come back as a string');
        $this->assertNull(Notifiables::key(new TestUser()), 'not saved: no key');
        $this->assertNull(Notifiables::key('a string'));
        $this->assertNull(Notifiables::key(null));
        $this->assertSame(7, Notifiables::key((object) ['id' => 7]));
        $this->assertSame('abc', Notifiables::key((object) ['id' => 'abc']));
        $this->assertNull(Notifiables::key((object) ['id' => '']));
        $this->assertSame(TestUser::class, Notifiables::type($this->user(1)));

        $this->expectException(\RuntimeException::class);
        Notifiables::requireKey(new TestUser());
    }

    public function testAttributesAreReadWithoutIssetOnModelsAndWithoutWarningsOnObjects(): void
    {
        $this->assertSame('Ann', Notifiables::attribute($this->user(1), 'name'));
        $this->assertNull(Notifiables::attribute($this->user(1), 'nonexistent'));
        $this->assertSame('x', Notifiables::attribute((object) ['email' => 'x'], 'email'));
        $this->assertNull(Notifiables::attribute((object) [], 'email'));
        $this->assertNull(Notifiables::attribute('nope', 'email'));
    }

    public function testTheManagerKnowsTheNewChannels(): void
    {
        $manager = new NotificationManager();

        foreach (['database', 'mail', 'slack', 'discord', 'webhook'] as $channel) {
            $this->assertTrue($manager->hasChannel($channel), $channel);
            $this->assertContains($channel, $manager->getChannels());
            $this->assertInstanceOf(\Doppar\Notifier\Channels\Contracts\ChannelDriver::class, $manager->channel($channel));
        }
        $this->assertFalse($manager->hasChannel('sms'));
    }

    public function testAChannelCanBeCreatedWithoutTheApplication(): void
    {
        $this->assertInstanceOf(\Doppar\Notifier\Channels\WebhookChannel::class, new \Doppar\Notifier\Channels\WebhookChannel());
        $this->assertInstanceOf(\Doppar\Notifier\Channels\MailChannel::class, new \Doppar\Notifier\Channels\MailChannel(null));
    }
}
