<?php

namespace Doppar\Notifier\Contracts;

use Doppar\Queue\InteractsWithModelSerialization;

abstract class Notification
{
    use InteractsWithModelSerialization;

    /**
     * Define the notification channels
     *
     * @param mixed $notifiable
     * @return array
     */
    abstract public function channels($notifiable): array;

    /**
     * Define the notification content
     *
     * @param mixed $notifiable
     * @return array
     */
    abstract public function content($notifiable): array;

    /**
     * Get the content for one channel
     *
     * @param string $channel
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function contentFor(string $channel, $notifiable): array
    {
        $method = 'to' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $channel)));

        if ($method !== 'to' && method_exists($this, $method)) {
            $content = $this->{$method}($notifiable);

            return is_array($content) ? $content : [];
        }

        return $this->content($notifiable);
    }

    /**
     * Get notification metadata
     *
     * @return array
     */
    public function metadata(): array
    {
        return [];
    }

    /**
     * Check if notification should be sent
     *
     * @param mixed $notifiable
     * @return bool
     */
    public function shouldSend($notifiable): bool
    {
        return true;
    }

    /**
     * Check if notification should be sent through one channel
     *
     * @param mixed $notifiable
     * @param string $channel
     * @return bool
     */
    public function shouldSendVia($notifiable, string $channel): bool
    {
        return true;
    }

    /**
     * Get the notification's delivery delay
     *
     * @return int
     */
    public function deliveryDelay(): int
    {
        return 0;
    }

    /**
     * Get the queue priority (-100 to 100, higher is delivered first)
     *
     * @return int
     */
    public function priority(): int
    {
        return 0;
    }

    /**
     * Get the key that makes the notification unique for a notifiable
     *
     * @param mixed $notifiable
     * @return string|null
     */
    public function uniqueId($notifiable): ?string
    {
        return null;
    }

    /**
     * Get the seconds to wait before each retry
     *
     * @return int|array<int, int>|null
     */
    public function backoff(): int|array|null
    {
        return null;
    }

    /**
     * Get the queue connection the notification is queued on
     *
     * @return string|null
     */
    public function onConnection(): ?string
    {
        return null;
    }

    /**
     * Get the limit on how often this notification reaches the same notifiable
     *
     * @return array{max: int, per: int}|null
     */
    public function throttle(): ?array
    {
        return null;
    }
}
