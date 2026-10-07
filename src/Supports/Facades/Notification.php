<?php

namespace Doppar\Notifier\Supports\Facades;

use Doppar\Notifier\Contracts\Notification as ContractsNotification;
use Doppar\Notifier\Concerns\ScheduledNotificationBuilder;
use Doppar\Notifier\Concerns\QueryNotificationBuilder;
use Doppar\Notifier\Concerns\NotificationBuilder;
use Doppar\Notifier\Concerns\BulkNotificationBuilder;
use Doppar\Notifier\NotificationEvents;
use Doppar\Notifier\Testing\NotificationFake;

class Notification
{
    /**
     * Send notification to a single entity
     *
     * @param mixed $notifiable
     * @return NotificationBuilder
     */
    public static function to($notifiable): NotificationBuilder
    {
        return new NotificationBuilder($notifiable);
    }

    /**
     * Send notification to multiple entities
     *
     * @param iterable $notifiables
     * @return BulkNotificationBuilder
     */
    public static function toMany(iterable $notifiables): BulkNotificationBuilder
    {
        return new BulkNotificationBuilder($notifiables);
    }

    /**
     * Send notification to all users matching criteria
     *
     * @param string $modelClass
     * @return QueryNotificationBuilder
     */
    public static function toAll(string $modelClass): QueryNotificationBuilder
    {
        return new QueryNotificationBuilder($modelClass);
    }

    /**
     * Schedule a notification
     *
     * @param ContractsNotification $notification
     * @return ScheduledNotificationBuilder
     */
    public static function schedule(ContractsNotification $notification): ScheduledNotificationBuilder
    {
        return new ScheduledNotificationBuilder($notification);
    }

    /**
     * Capture notifications instead of sending them
     *
     * @return NotificationFake
     */
    public static function fake(): NotificationFake
    {
        return NotificationFake::activate();
    }

    /**
     * Send notifications for real again after fake()
     *
     * @return void
     */
    public static function unfake(): void
    {
        NotificationFake::deactivate();
    }

    /**
     * Listen for a delivery that is about to be made
     *
     * The listener receives the notifiable, the notification and the channel.
     * Returning false cancels that delivery.
     *
     * @param callable $listener
     * @return void
     */
    public static function sending(callable $listener): void
    {
        NotificationEvents::listen(NotificationEvents::SENDING, $listener);
    }

    /**
     * Listen for a delivery that succeeded
     *
     * @param callable $listener
     * @return void
     */
    public static function sent(callable $listener): void
    {
        NotificationEvents::listen(NotificationEvents::SENT, $listener);
    }

    /**
     * Listen for a delivery that failed
     *
     * @param callable $listener
     * @return void
     */
    public static function failed(callable $listener): void
    {
        NotificationEvents::listen(NotificationEvents::FAILED, $listener);
    }

    /**
     * Remove every delivery listener
     *
     * @return void
     */
    public static function flushListeners(): void
    {
        NotificationEvents::flush();
    }
}
