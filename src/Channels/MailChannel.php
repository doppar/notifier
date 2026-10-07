<?php

namespace Doppar\Notifier\Channels;

use Phaseolies\Support\Facades\Mail;
use Phaseolies\Support\Mail\Mailable;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;
use Doppar\Notifier\Channels\Support\MailTemplate;
use Doppar\Notifier\Support\Notifiables;

class MailChannel extends ChannelDriver
{
    /**
     * The function that hands the mail over, replaced by tests
     *
     * @var (callable(string, string|null, Mailable, array<string, mixed>): void)|null
     */
    protected static $sender = null;

    /**
     * Replace how mail is handed over, or restore the application mailer with null
     *
     * @param callable|null $sender
     * @return void
     */
    public static function using(?callable $sender): void
    {
        static::$sender = $sender;
    }

    /**
     * Send the notification as an email
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    public function send($notifiable, Notification $notification): void
    {
        $address = $notifiable->routeNotificationFor('mail');

        if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Mail address is missing or invalid.');
        }

        $content = $notification->contentFor('mail', $notifiable);
        $mailable = $this->buildMailable($content);
        $name = Notifiables::attribute($notifiable, 'name');
        $name = is_string($name) && $name !== '' ? $name : null;

        if (static::$sender !== null) {
            (static::$sender)($address, $name, $mailable, $content);

            return;
        }

        $mail = Mail::to($address, $name);

        foreach (['cc', 'bcc'] as $field) {
            if (!empty($content[$field])) {
                $mail->{$field}($content[$field]);
            }
        }

        $mail->send($mailable);
    }

    /**
     * Build the Mailable for the mail content
     *
     * @param array<string, mixed> $content
     * @return Mailable
     */
    protected function buildMailable(array $content): Mailable
    {
        $mailable = new Mailable();
        $mailable->subject = (string) ($content['subject'] ?? 'Notification');

        $rendered = isset($content['html']) ? null : MailTemplate::render($content);
        $mailable->html((string) ($content['html'] ?? $rendered['html'] ?? ''));

        $text = $content['text'] ?? $rendered['text'] ?? null;

        if (is_string($text) && $text !== '') {
            $mailable->text($text);
        }

        if (!empty($content['replyTo'])) {
            $mailable->replyTo($content['replyTo']);
        }

        foreach ((array) ($content['tags'] ?? []) as $tag) {
            $mailable->tag((string) $tag);
        }

        if (isset($content['priority'])) {
            $mailable->priority((int) $content['priority']);
        }

        foreach ((array) ($content['attachments'] ?? []) as $attachment) {
            $attachment = is_array($attachment) ? $attachment : ['path' => $attachment];

            $mailable->attach((string) $attachment['path'], $attachment['name'] ?? null, $attachment['mime'] ?? null);
        }

        return $mailable;
    }
}
