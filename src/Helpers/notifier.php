<?php

use Doppar\Notifier\Supports\Facades\Notification;
use Doppar\Notifier\Concerns\NotificationBuilder;

/**
 * Global helper function
 *
 * @return Notification|NotificationBuilder
 */
if (!function_exists('notify')) {
    function notify($notifiable = null): Notification|NotificationBuilder
    {
        if ($notifiable === null) {
            return new Notification();
        }

        return Notification::to($notifiable);
    }
}