<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Channels\DatabaseChannel;
use Doppar\Notifier\Channels\DiscordChannel;
use Doppar\Notifier\Channels\SlackChannel;
use Doppar\Notifier\Channels\WebhookChannel;
use Doppar\Notifier\Tests\Mock\Models\TestUser;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class ChannelsTest extends NotifierTestCase
{
    public function testTheDatabaseChannelStoresTheNotificationForTheEntity(): void
    {
        (new DatabaseChannel())->send($this->user(2), new ConfigurableNotification(['database']));

        $row = $this->rows('SELECT * FROM notifications')[0];
        $this->assertSame(TestUser::class, $row['notifiable_type']);
        $this->assertSame(2, (int) $row['notifiable_id']);
        $this->assertSame(ConfigurableNotification::class, $row['type']);
        $this->assertSame('Hello', json_decode($row['data'], true)['subject']);
        $this->assertSame(['source' => 'test'], json_decode($row['metadata'], true));
        $this->assertNull($row['read_at']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testTheDatabaseChannelRefusesAnEntityWithoutAKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no key');

        (new DatabaseChannel())->send(new TestUser(), new ConfigurableNotification(['database']));
    }

    public function testSlackPostsTheContentToTheWebhook(): void
    {
        (new SlackChannel())->send($this->user(1), new ConfigurableNotification(['slack']));

        $this->assertCount(1, $this->http);
        $this->assertSame('https://hooks.slack.test/1', $this->http[0]['url']);
        $this->assertContains('Content-Type: application/json', $this->http[0]['headers']);
        $payload = json_decode($this->http[0]['body'], true);
        $this->assertSame('Hello Slack', $payload['text']);
        $this->assertSame('Doppar Bot', $payload['username']);
        $this->assertSame(':bell:', $payload['icon_emoji']);
    }

    public function testSlackFailureThrowsSoTheQueueRetries(): void
    {
        $this->httpAnswer = ['status' => 404, 'body' => 'no_service', 'error' => ''];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        (new SlackChannel())->send($this->user(1), new ConfigurableNotification(['slack']));
    }

    public function testSlackWithoutAWebhookThrows(): void
    {
        $this->pdo->exec('UPDATE users SET slack_webhook_url = NULL WHERE id = 1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Slack webhook URL is missing');

        (new SlackChannel())->send($this->user(1), new ConfigurableNotification(['slack']));
        $this->assertSame([], $this->http, 'nothing was sent');
    }

    public function testSlackAnswerThatIsNotOkIsAFailureEvenWith200(): void
    {
        $this->httpAnswer = ['status' => 200, 'body' => 'invalid_payload', 'error' => ''];

        $this->expectException(\RuntimeException::class);

        (new SlackChannel())->send($this->user(1), new ConfigurableNotification(['slack']));
    }

    public function testDiscordPostsAndFailsOnAnErrorStatus(): void
    {
        $this->httpAnswer = ['status' => 204, 'body' => '', 'error' => ''];
        (new DiscordChannel())->send($this->user(1), new ConfigurableNotification(['discord']));

        $this->assertSame('https://discord.test/hook/1', $this->http[0]['url']);
        $this->assertSame('Hello Discord', json_decode($this->http[0]['body'], true)['content']);

        $this->httpAnswer = ['status' => 429, 'body' => '', 'error' => ''];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('429');
        (new DiscordChannel())->send($this->user(1), new ConfigurableNotification(['discord']));
    }

    public function testTheWebhookChannelPostsTheWebhookContent(): void
    {
        $this->httpAnswer = ['status' => 202, 'body' => '', 'error' => ''];

        (new WebhookChannel())->send($this->user(3), new ConfigurableNotification(['webhook']));

        $this->assertSame('https://hooks.example.test/3', $this->http[0]['url']);
        $this->assertSame(['event' => 'welcome', 'user' => 3], json_decode($this->http[0]['body'], true));
        $this->assertSame(['Content-Type: application/json'], $this->http[0]['headers'], 'no secret, no signature');
    }

    public function testTheWebhookBodyIsSignedWhenThereIsASecret(): void
    {
        $notification = new class (['webhook']) extends ConfigurableNotification {
            public function toWebhook($notifiable): array
            {
                return ['payload' => ['id' => 7], 'secret' => 's3cret', 'headers' => ['X-Trace' => 'abc', "Evil\r\nInjected" => 'x', 'Content-Type' => 'text/plain']];
            }
        };

        (new WebhookChannel())->send($this->user(1), $notification);

        $call = $this->http[0];
        $this->assertSame('{"id":7}', $call['body'], 'only the payload key is sent');
        $this->assertContains('X-Notification-Signature: sha256=' . hash_hmac('sha256', '{"id":7}', 's3cret'), $call['headers']);
        $this->assertContains('X-Trace: abc', $call['headers']);
        $this->assertSame(['Content-Type: application/json'], array_values(array_filter($call['headers'], fn ($h) => stripos($h, 'content-type') === 0)), 'the content type cannot be overridden');
        $this->assertSame([], array_values(array_filter($call['headers'], fn ($h) => str_contains($h, 'Injected'))), 'a header with a line break is dropped');
    }

    public function testTheWebhookSecretCanComeFromTheConfig(): void
    {
        config(['notification.webhook.secret' => 'from-config']);

        (new WebhookChannel())->send($this->user(1), new ConfigurableNotification(['webhook']));

        $this->assertContains('X-Notification-Signature: sha256=' . hash_hmac('sha256', $this->http[0]['body'], 'from-config'), $this->http[0]['headers']);
        config(['notification.webhook.secret' => null]);
    }

    public function testTheWebhookNeedsAnHttpAddressAndASuccessfulAnswer(): void
    {
        foreach ([null, 'ftp://example.test/x', 'javascript:alert(1)', 'not a url'] as $url) {
            $this->pdo->prepare('UPDATE users SET webhook_url = ? WHERE id = 1')->execute([$url]);
            try {
                (new WebhookChannel())->send($this->user(1), new ConfigurableNotification(['webhook']));
                $this->fail('A webhook to ' . var_export($url, true) . ' must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Webhook URL', $e->getMessage());
            }
        }
        $this->assertSame([], $this->http);

        $this->pdo->exec("UPDATE users SET webhook_url = 'https://hooks.example.test/1' WHERE id = 1");
        $this->httpAnswer = ['status' => 500, 'body' => 'boom', 'error' => ''];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        (new WebhookChannel())->send($this->user(1), new ConfigurableNotification(['webhook']));
    }

    public function testEachChannelGetsItsOwnContent(): void
    {
        $notification = new ConfigurableNotification();

        $this->assertSame('Hello', $notification->contentFor('database', null)['subject'], 'no toDatabase: the shared content');
        $this->assertSame('Welcome', $notification->contentFor('mail', $this->user(1))['subject'], 'toMail');
        $this->assertSame(['event' => 'welcome', 'user' => 1], $notification->contentFor('webhook', $this->user(1)));
        $this->assertSame('Hello Slack', $notification->contentFor('slack', null)['text'], 'no toSlack: the shared content');
        $this->assertSame('Hello', $notification->contentFor('some-new_channel', null)['subject']);
    }
}
