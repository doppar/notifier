<?php

namespace Doppar\Notifier;

use Phaseolies\Support\Facades\Log;
use Doppar\Notifier\Contracts\Notification;

final class NotificationEvents
{
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /**
     * The registered listeners, by event
     *
     * @var array<string, array<int, callable>>
     */
    private static array $listeners = [];

    /**
     * Register a listener for an event
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Announce a delivery that is about to happen and learn whether it may go on
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @param string $channel
     * @return bool
     */
    public static function sending(mixed $notifiable, Notification $notification, string $channel): bool
    {
        foreach (self::run(self::SENDING, [$notifiable, $notification, $channel]) as $result) {
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Announce a delivery that succeeded
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @param string $channel
     * @return void
     */
    public static function sent(mixed $notifiable, Notification $notification, string $channel): void
    {
        self::run(self::SENT, [$notifiable, $notification, $channel]);
    }

    /**
     * Announce a delivery that failed
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @param string $channel
     * @param \Throwable $exception
     * @return void
     */
    public static function failed(mixed $notifiable, Notification $notification, string $channel, \Throwable $exception): void
    {
        self::run(self::FAILED, [$notifiable, $notification, $channel, $exception]);
    }

    /**
     * Remove every listener
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$listeners = [];
    }

    /**
     * Call the listeners of an event, isolating a listener that throws
     *
     * @param string $event
     * @param array<int, mixed> $arguments
     * @return array<int, mixed>
     */
    private static function run(string $event, array $arguments): array
    {
        $results = [];

        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $results[] = $listener(...$arguments);
            } catch (\Throwable $e) {
                // A broken listener must never stop or fail a delivery.
                try {
                    Log::error("Notification {$event} listener failed: " . $e->getMessage());
                } catch (\Throwable) {
                }
            }
        }

        return $results;
    }
}
