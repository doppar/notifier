<?php

namespace Doppar\Notifier\Tests\Mock\Notifications;

use Doppar\Notifier\Contracts\Notification;

class ConfigurableNotification extends Notification
{
    /**
     * @param array<int, string> $channels
     * @param array<string, mixed> $options
     */
    public function __construct(public array $channels = ['database'], public array $options = [])
    {
    }

    public function channels($notifiable): array
    {
        return $this->channels;
    }

    public function content($notifiable): array
    {
        return ['subject' => 'Hello', 'message' => 'World', 'text' => 'Hello Slack', 'content' => 'Hello Discord'];
    }

    public function toMail($notifiable): array
    {
        return [
            'subject' => 'Welcome',
            'greeting' => 'Hi ' . ($notifiable->name ?? 'there'),
            'lines' => ['Your account is ready.'],
            'action' => ['text' => 'Open', 'url' => 'https://app.example.com/start'],
        ];
    }

    public function toWebhook($notifiable): array
    {
        return ['event' => 'welcome', 'user' => $notifiable->id];
    }

    public function shouldSend($notifiable): bool
    {
        return $this->options['shouldSend'] ?? true;
    }

    public function shouldSendVia($notifiable, string $channel): bool
    {
        return !in_array($channel, $this->options['skipChannels'] ?? [], true);
    }

    public function deliveryDelay(): int
    {
        return $this->options['delay'] ?? 0;
    }

    public function priority(): int
    {
        return $this->options['priority'] ?? 0;
    }

    public function uniqueId($notifiable): ?string
    {
        return $this->options['uniqueId'] ?? null;
    }

    public function backoff(): int|array|null
    {
        return $this->options['backoff'] ?? null;
    }

    public function onConnection(): ?string
    {
        return $this->options['connection'] ?? null;
    }

    public function throttle(): ?array
    {
        return $this->options['throttle'] ?? null;
    }

    public function metadata(): array
    {
        return ['source' => 'test'];
    }
}
