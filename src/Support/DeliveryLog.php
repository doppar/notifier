<?php

namespace Doppar\Notifier\Support;

use Phaseolies\Support\Facades\Log;
use Doppar\Notifier\Models\NotificationDelivery;
use Doppar\Notifier\Contracts\Notification;

/**
 * Keeps one row per notification and channel in notification_deliveries
 *
 * The log is best effort. Sending never depends on it: when the table does not
 * exist (an app that has not run the migration yet) every method quietly does
 * nothing, and the features that need it (skipping a channel that already
 * delivered, throttling) are simply off.
 */
final class DeliveryLog
{
    /**
     * Whether the log has been found unusable in this process
     *
     * @var bool
     */
    private static bool $broken = false;

    /**
     * Check if the channel already delivered this notification
     *
     * @param string $notificationId
     * @param string $channel
     * @return bool
     */
    public static function alreadySent(string $notificationId, string $channel): bool
    {
        return self::guard(function () use ($notificationId, $channel): bool {
            $row = NotificationDelivery::query()
                ->where('delivery_key', self::key($notificationId, $channel))
                ->first();

            return $row !== null && $row->status === 'sent';
        }, false);
    }

    /**
     * Record what happened to a notification on one channel
     *
     * @param string $notificationId
     * @param string $channel
     * @param mixed $notifiable
     * @param Notification $notification
     * @param string $status
     * @param string|null $error
     * @return void
     */
    public static function record(
        string $notificationId,
        string $channel,
        mixed $notifiable,
        Notification $notification,
        string $status,
        ?string $error = null
    ): void {
        self::guard(function () use ($notificationId, $channel, $notifiable, $notification, $status, $error): void {
            $now = date('Y-m-d H:i:s');
            $attempted = in_array($status, ['sent', 'failed'], true);
            $row = NotificationDelivery::query()
                ->where('delivery_key', self::key($notificationId, $channel))
                ->first();

            if ($row === null) {
                NotificationDelivery::create([
                    'delivery_key' => self::key($notificationId, $channel),
                    'notification_id' => $notificationId,
                    'notification_type' => get_class($notification),
                    'notifiable_type' => Notifiables::type($notifiable),
                    'notifiable_id' => (int) Notifiables::key($notifiable),
                    'channel' => $channel,
                    'status' => $status,
                    'attempts' => $attempted ? 1 : 0,
                    'error' => $error,
                    'sent_at' => $status === 'sent' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            }

            $row->status = $status;
            $row->attempts = (int) $row->attempts + ($attempted ? 1 : 0);
            $row->error = $status === 'sent' ? null : $error;
            $row->sent_at = $status === 'sent' ? $now : $row->sent_at;
            $row->updated_at = $now;
            $row->save();
        }, null);
    }

    /**
     * Count the deliveries a channel made to a notifiable since a time
     *
     * @param mixed $notifiable
     * @param string $notificationClass
     * @param string $channel
     * @param int $since Unix timestamp
     * @return int
     */
    public static function countSent(mixed $notifiable, string $notificationClass, string $channel, int $since): int
    {
        return self::guard(function () use ($notifiable, $notificationClass, $channel, $since): int {
            return (int) NotificationDelivery::query()
                ->where('notifiable_type', Notifiables::type($notifiable))
                ->where('notifiable_id', Notifiables::key($notifiable))
                ->where('notification_type', $notificationClass)
                ->where('channel', $channel)
                ->where('status', 'sent')
                ->where('sent_at', '>=', date('Y-m-d H:i:s', $since))
                ->count();
        }, 0);
    }

    /**
     * Forget that the log was found unusable
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$broken = false;
    }

    /**
     * Get the unique key of a notification on a channel
     *
     * @param string $notificationId
     * @param string $channel
     * @return string
     */
    private static function key(string $notificationId, string $channel): string
    {
        return substr($notificationId . '|' . $channel, 0, 100);
    }

    /**
     * Run a log operation and fall back when the log cannot be used
     *
     * @param \Closure $operation
     * @param mixed $fallback
     * @return mixed
     */
    private static function guard(\Closure $operation, mixed $fallback): mixed
    {
        if (self::$broken) {
            return $fallback;
        }

        try {
            return $operation();
        } catch (\Throwable $e) {
            self::$broken = true;

            try {
                Log::warning('Notification delivery log is unavailable, run the notifier migrations: ' . $e->getMessage());
            } catch (\Throwable) {
                // The log is best effort, so even a failing logger must not stop a delivery.
            }

            return $fallback;
        }
    }
}
