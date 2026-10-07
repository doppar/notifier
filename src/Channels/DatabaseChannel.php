<?php

namespace Doppar\Notifier\Channels;

use Doppar\Notifier\Models\DatabaseNotification;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;
use Doppar\Notifier\Support\Notifiables;

class DatabaseChannel extends ChannelDriver
{
    /**
     * Store the notification so the notifiable can read it in the application
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    public function send($notifiable, Notification $notification): void
    {
        DatabaseNotification::create([
            'notifiable_type' => Notifiables::type($notifiable),
            'notifiable_id' => Notifiables::requireKey($notifiable),
            'type' => get_class($notification),
            'data' => json_encode($notification->contentFor('database', $notifiable), JSON_THROW_ON_ERROR),
            'metadata' => json_encode($notification->metadata(), JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
