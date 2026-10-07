<?php

namespace Doppar\Notifier\Tests\Unit;

use Doppar\Notifier\Channels\MailChannel;
use Doppar\Notifier\Channels\Support\MailTemplate;
use Doppar\Notifier\Tests\Mock\Notifications\ConfigurableNotification;
use Doppar\Notifier\Tests\Support\NotifierTestCase;

class MailChannelTest extends NotifierTestCase
{
    public function testTheMailGoesToTheNotifiablesEmailWithTheContentOfToMail(): void
    {
        (new MailChannel())->send($this->user(1), new ConfigurableNotification(['mail']));

        $this->assertCount(1, $this->mails);
        $mail = $this->mails[0];
        $this->assertSame('ann@example.com', $mail['address']);
        $this->assertSame('Ann', $mail['name']);
        $this->assertSame('Welcome', $mail['mailable']->subject);
        $this->assertStringContainsString('Hi Ann', $mail['mailable']->body);
        $this->assertStringContainsString('Your account is ready.', $mail['mailable']->body);
        $this->assertStringContainsString('href="https://app.example.com/start"', $mail['mailable']->body);
        $this->assertStringContainsString('Open: https://app.example.com/start', (string) $mail['mailable']->textBody);
    }

    public function testTheAddressCanBeRoutedAndMustBeValid(): void
    {
        $routed = new class extends \Doppar\Notifier\Tests\Mock\Models\TestUser {
            public function routeNotificationForMail(): string
            {
                return 'billing@example.com';
            }
        };
        $routed->id = 1;
        $routed->name = 'Ann';
        (new MailChannel())->send($routed, new ConfigurableNotification(['mail']));
        $this->assertSame('billing@example.com', $this->mails[0]['address']);

        foreach ([null, '', 'not-an-email', "a@b.com\r\nBcc: x@y.com"] as $address) {
            $this->pdo->prepare('UPDATE users SET email = ? WHERE id = 2')->execute([$address ?? '']);
            try {
                (new MailChannel())->send($this->user(2), new ConfigurableNotification(['mail']));
                $this->fail('Mail to ' . var_export($address, true) . ' must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Mail address', $e->getMessage());
            }
        }
        $this->assertCount(1, $this->mails, 'nothing was sent to an invalid address');
    }

    public function testHtmlAndTextCanBeGivenDirectlyAndTheOptionsAreApplied(): void
    {
        $notification = new class (['mail']) extends ConfigurableNotification {
            public function toMail($notifiable): array
            {
                return [
                    'subject' => 'Invoice',
                    'html' => '<b>Pay</b>',
                    'text' => 'Pay',
                    'replyTo' => 'billing@example.com',
                    'tags' => ['invoice', 'monthly'],
                    'priority' => 1,
                    'cc' => ['boss@example.com'],
                ];
            }
        };

        (new MailChannel())->send($this->user(1), $notification);

        $mailable = $this->mails[0]['mailable'];
        $this->assertSame('Invoice', $mailable->subject);
        $this->assertSame('<b>Pay</b>', $mailable->body);
        $this->assertSame('Pay', $mailable->textBody);
        $this->assertSame(1, $mailable->priority);
        $this->assertSame(['boss@example.com'], $this->mails[0]['content']['cc']);
        $this->assertNotEmpty($mailable->replyTo);
        $this->assertNotEmpty($mailable->tags);
    }

    public function testTheTemplateEscapesEverything(): void
    {
        $out = MailTemplate::render([
            'greeting' => '<script>alert(1)</script>',
            'lines' => ['<img src=x onerror=alert(2)>', 'Fish & "chips"'],
            'action' => ['text' => '<b>Go</b>', 'url' => 'https://example.com/?a=1&b=2'],
            'footer' => '<i>bye</i>',
        ]);

        $this->assertStringNotContainsString('<script>', $out['html']);
        $this->assertStringNotContainsString('<img', $out['html']);
        $this->assertStringContainsString('&lt;script&gt;', $out['html']);
        $this->assertStringContainsString('Fish &amp; &quot;chips&quot;', $out['html']);
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $out['html']);
        $this->assertStringNotContainsString('<b>Go</b>', $out['html']);
        $this->assertStringContainsString('<script>alert(1)</script>', $out['text'], 'the plain text version is text, not HTML');
    }

    public function testTheActionLinkMustBeAPlainHttpAddress(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test', 'https://a.test/" onclick="x', "https://a.test/x y", 'ftp://a.test'] as $url) {
            try {
                MailTemplate::render(['action' => ['text' => 'Go', 'url' => $url]]);
                $this->fail("The link $url must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('http(s)', $e->getMessage());
            }
        }
    }

    public function testTheTemplateWorksWithOnlySomeParts(): void
    {
        $this->assertSame('Just one line.', MailTemplate::render(['lines' => 'Just one line.'])['text']);
        $this->assertSame('', MailTemplate::render([])['text']);
        $this->assertStringNotContainsString('<a ', MailTemplate::render(['lines' => ['x'], 'action' => ['text' => 'Go']])['html'], 'no url, no button');
    }
}
