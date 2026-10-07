<?php

namespace Doppar\Notifier\Testing;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Support\Id;
use Doppar\Notifier\Support\Notifiables;

final class NotificationFake
{
    /**
     * The fake in use, or null when notifications are really sent
     *
     * @var self|null
     */
    private static ?self $active = null;

    /**
     * Everything that would have been sent
     *
     * @var array<int, array{id: string, notifiable: mixed, notification: Notification, channels: array<int, string>, delay: int, immediate: bool}>
     */
    private array $sent = [];

    /**
     * Start capturing notifications instead of sending them
     *
     * @return self
     */
    public static function activate(): self
    {
        return self::$active = new self();
    }

    /**
     * Go back to sending notifications for real
     *
     * @return void
     */
    public static function deactivate(): void
    {
        self::$active = null;
    }

    /**
     * Get the fake in use
     *
     * @return self|null
     */
    public static function current(): ?self
    {
        return self::$active;
    }

    /**
     * Record a notification that would have been sent
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @param array<int, string> $channels
     * @param int $delay
     * @param bool $immediate
     * @return string
     */
    public function record(mixed $notifiable, Notification $notification, array $channels, int $delay, bool $immediate): string
    {
        $id = Id::uuid();

        $this->sent[] = [
            'id' => $id,
            'notifiable' => $notifiable,
            'notification' => $notification,
            'channels' => array_values($channels),
            'delay' => $delay,
            'immediate' => $immediate,
        ];

        return $id;
    }

    /**
     * Get what was sent, optionally only to one notifiable and of one kind
     *
     * @param mixed $notifiable
     * @param string|\Closure|null $notification
     * @return array<int, array{id: string, notifiable: mixed, notification: Notification, channels: array<int, string>, delay: int, immediate: bool}>
     */
    public function sent(mixed $notifiable = null, string|\Closure|null $notification = null): array
    {
        return array_values(array_filter($this->sent, function (array $entry) use ($notifiable, $notification): bool {
            if ($notifiable !== null && !$this->sameNotifiable($entry['notifiable'], $notifiable)) {
                return false;
            }

            return $notification === null || $this->matches($entry, $notification);
        }));
    }

    /**
     * Assert that a notification was sent to a notifiable
     *
     * @param mixed $notifiable
     * @param string|\Closure $notification
     * @param int|null $times
     * @return void
     */
    public function assertSentTo(mixed $notifiable, string|\Closure $notification, ?int $times = null): void
    {
        $count = count($this->sent($notifiable, $notification));
        $name = $this->describe($notification);

        if ($count === 0 || ($times !== null && $count !== $times)) {
            $this->fail($times === null
                ? "The expected [{$name}] notification was not sent to the notifiable."
                : "Expected [{$name}] to be sent {$times} time(s) to the notifiable, but it was sent {$count} time(s).");
        }
    }

    /**
     * Assert that a notification was not sent to a notifiable
     *
     * @param mixed $notifiable
     * @param string|\Closure $notification
     * @return void
     */
    public function assertNotSentTo(mixed $notifiable, string|\Closure $notification): void
    {
        if ($this->sent($notifiable, $notification) !== []) {
            $this->fail('The unexpected [' . $this->describe($notification) . '] notification was sent to the notifiable.');
        }
    }

    /**
     * Assert that a notification was sent a number of times, to anybody
     *
     * @param string|\Closure $notification
     * @param int $times
     * @return void
     */
    public function assertSentTimes(string|\Closure $notification, int $times): void
    {
        $count = count($this->sent(null, $notification));

        if ($count !== $times) {
            $this->fail("Expected [" . $this->describe($notification) . "] to be sent {$times} time(s), but it was sent {$count} time(s).");
        }
    }

    /**
     * Assert that a notification went out through a channel
     *
     * @param mixed $notifiable
     * @param string $notification
     * @param string $channel
     * @return void
     */
    public function assertSentVia(mixed $notifiable, string $notification, string $channel): void
    {
        foreach ($this->sent($notifiable, $notification) as $entry) {
            if (in_array($channel, $entry['channels'], true)) {
                return;
            }
        }

        $this->fail("The [{$notification}] notification was not sent through the [{$channel}] channel.");
    }

    /**
     * Assert that nothing was sent
     *
     * @return void
     */
    public function assertNothingSent(): void
    {
        if ($this->sent !== []) {
            $this->fail('Expected no notifications, but ' . count($this->sent) . ' were sent.');
        }
    }

    /**
     * Assert the total number of notifications sent
     *
     * @param int $count
     * @return void
     */
    public function assertCount(int $count): void
    {
        if (count($this->sent) !== $count) {
            $this->fail("Expected {$count} notification(s) to be sent, but " . count($this->sent) . ' were sent.');
        }
    }

    /**
     * Check if two values are the same notifiable entity
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private function sameNotifiable(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $keyA = Notifiables::key($a);

        return $keyA !== null
            && Notifiables::type($a) === Notifiables::type($b)
            && (string) $keyA === (string) Notifiables::key($b);
    }

    /**
     * Check if a recorded notification matches a class name or a callback
     *
     * @param array{id: string, notifiable: mixed, notification: Notification, channels: array<int, string>, delay: int, immediate: bool} $entry
     * @param string|\Closure $notification
     * @return bool
     */
    private function matches(array $entry, string|\Closure $notification): bool
    {
        if ($notification instanceof \Closure) {
            return (bool) $notification($entry['notification'], $entry['channels'], $entry['notifiable']);
        }

        return $entry['notification'] instanceof $notification;
    }

    /**
     * Get a readable name for what an assertion looked for
     *
     * @param string|\Closure $notification
     * @return string
     */
    private function describe(string|\Closure $notification): string
    {
        return $notification instanceof \Closure ? 'matching' : $notification;
    }

    /**
     * Fail an assertion
     *
     * @param string $message
     * @return never
     */
    private function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\AssertionFailedError::class)) {
            throw new \PHPUnit\Framework\AssertionFailedError($message);
        }

        throw new \RuntimeException($message);
    }
}
