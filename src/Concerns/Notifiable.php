<?php

namespace Doppar\Notifier\Concerns;

use Phaseolies\Support\Collection;
use Doppar\Notifier\Models\DatabaseNotification;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Concerns\NotificationPipeline;

trait Notifiable
{
    /**
     * Send a notification to this entity
     *
     * @param Notification $notification
     * @return NotificationPipeline
     */
    public function notify(Notification $notification): NotificationPipeline
    {
        return new NotificationPipeline($this, $notification);
    }

    /**
     * Send notification immediately (sync)
     *
     * @param Notification $notification
     * @return void
     */
    public function notifyNow(Notification $notification): void
    {
        (new NotificationPipeline($this, $notification))->immediate();
    }

    /**
     * Get all notifications for this entity
     *
     * @return \Phaseolies\Support\Collection
     */
    public function notifications(): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', get_class($this))
            ->where('notifiable_id', auth()?->id())
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Get unread notifications
     *
     * @return \Phaseolies\Support\Collection
     */
    public function unreadNotifications(): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', get_class($this))
            ->where('notifiable_id', auth()?->id())
            ->whereNull('read_at')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Get read notifications
     *
     * @return \Phaseolies\Support\Collection
     */
    public function readNotifications(): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', get_class($this))
            ->where('notifiable_id', auth()?->id())
            ->whereNotNull('read_at')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Mark all notifications as read
     *
     * @return int
     */
    public function markNotificationsAsRead(): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', get_class($this))
            ->where('notifiable_id', auth()?->id())
            ->whereNull('read_at')
            ->update([
                'read_at' => now()
            ]);
    }

    /**
     * Get routing info for a notification channel
     *
     * @param string $channel
     * @return mixed
     */
    public function routeNotificationFor(string $channel): mixed
    {
        $method = 'routeNotificationFor' . ucfirst($channel);

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return match ($channel) {
            'slack' => $this->slack_webhook_url ?? null,
            'discord' => $this->discord_webhook_url ?? null,
            default => null,
        };
    }
}
