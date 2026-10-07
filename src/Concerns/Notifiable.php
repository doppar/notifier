<?php

namespace Doppar\Notifier\Concerns;

use Phaseolies\Support\Collection;
use Doppar\Notifier\Models\DatabaseNotification;
use Doppar\Notifier\Models\NotificationDelivery;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Concerns\NotificationPipeline;
use Doppar\Notifier\Support\Notifiables;

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
        return $this->notificationsQuery()
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
        return $this->notificationsQuery()
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
        return $this->notificationsQuery()
            ->whereNotNull('read_at')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Count the unread notifications
     *
     * @return int
     */
    public function unreadNotificationsCount(): int
    {
        return (int) $this->notificationsQuery()
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark all notifications as read
     *
     * @return int
     */
    public function markNotificationsAsRead(): int
    {
        $unread = $this->unreadNotificationsCount();

        if ($unread > 0) {
            $this->notificationsQuery()
                ->whereNull('read_at')
                ->update([
                    'read_at' => date('Y-m-d H:i:s')
                ]);
        }

        return $unread;
    }

    /**
     * Mark one of this entity's notifications as read
     *
     * @param int|string $id
     * @return bool
     */
    public function markNotificationAsRead(int|string $id): bool
    {
        $notification = $this->notificationsQuery()
            ->where('id', $id)
            ->first();

        return $notification !== null && $notification->markAsRead();
    }

    /**
     * Delete one of this entity's notifications
     *
     * @param int|string $id
     * @return bool
     */
    public function deleteNotification(int|string $id): bool
    {
        if ((int) $this->notificationsQuery()->where('id', $id)->count() === 0) {
            return false;
        }

        $this->notificationsQuery()
            ->where('id', $id)
            ->delete();

        return true;
    }

    /**
     * Delete the notifications this entity has read
     *
     * @return int
     */
    public function deleteReadNotifications(): int
    {
        $read = (int) $this->notificationsQuery()
            ->whereNotNull('read_at')
            ->count();

        if ($read > 0) {
            $this->notificationsQuery()
                ->whereNotNull('read_at')
                ->delete();
        }

        return $read;
    }

    /**
     * Get what happened to the notifications sent to this entity, newest first
     *
     * @return \Phaseolies\Support\Collection
     */
    public function notificationDeliveries(): Collection
    {
        return NotificationDelivery::query()
            ->where('notifiable_type', Notifiables::type($this))
            ->where('notifiable_id', Notifiables::requireKey($this))
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Decide if this entity wants a notification on a channel
     *
     * @param Notification $notification
     * @param string $channel
     * @return bool
     */
    public function wantsNotification(Notification $notification, string $channel): bool
    {
        return true;
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
            'mail' => Notifiables::attribute($this, 'email'),
            'slack' => Notifiables::attribute($this, 'slack_webhook_url'),
            'discord' => Notifiables::attribute($this, 'discord_webhook_url'),
            'webhook' => Notifiables::attribute($this, 'webhook_url'),
            default => null,
        };
    }

    /**
     * Start a query for this entity's stored notifications
     *
     * @return mixed
     */
    protected function notificationsQuery(): mixed
    {
        $key = Notifiables::key($this);

        return DatabaseNotification::query()
            ->where('notifiable_type', Notifiables::type($this))
            ->where('notifiable_id', $key ?? 0);
    }
}
