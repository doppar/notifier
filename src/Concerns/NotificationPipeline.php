<?php

namespace Doppar\Notifier\Concerns;

use Doppar\Notifier\NotificationDispatcher;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Support\Id;
use Doppar\Notifier\Testing\NotificationFake;

class NotificationPipeline
{
    /**
     * The entity that will receive the notification
     *
     * @var mixed
     */
    protected mixed $notifiable;

    /**
     * The notification instance to be sent to the notifiable
     *
     * @var Notification
     */
    protected Notification $notification;

    /**
     * Array of channels to send the notification through
     *
     * @var array|null
     */
    protected ?array $channels = null;

    /**
     * Delay in seconds before sending the notification
     *
     * @var int
     */
    protected int $delay = 0;

    /**
     * Whether the notification should be queued for asynchronous sending
     *
     * @var bool
     */
    protected bool $shouldQueue = true;

    /**
     * Tracks if the notification has already been sent
     *
     * @var bool
     */
    protected bool $sent = false;

    /**
     * @param mixed $notifiable
     * @param Notification $notification
     */
    public function __construct(mixed $notifiable, Notification $notification)
    {
        $this->notifiable = $notifiable;
        $this->notification = $notification;
    }

    /**
     * Send via specific channels only
     *
     * @param array $channels
     * @return self
     */
    public function via(array $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Send after a delay (in seconds)
     *
     * @param int $seconds
     * @return self
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;

        return $this;
    }

    /**
     * Send immediately without queuing
     *
     * @return void
     */
    public function immediate(): void
    {
        $this->shouldQueue = false;

        $this->send();
    }

    /**
     * Send the notification
     *
     * @return mixed
     */
    public function send(): mixed
    {
        if ($this->sent) {
            return null;
        }

        $channels = $this->channels ?? $this->allowedChannels($this->notification->channels($this->notifiable));

        if (empty($channels)) {
            return null;
        }

        $channels = array_values(array_unique(array_map('strval', $channels)));
        $delay = $this->delay ?: $this->notification->deliveryDelay();

        $this->sent = true;

        $fake = NotificationFake::current();

        if ($fake !== null) {
            return $fake->record($this->notifiable, $this->notification, $channels, $delay, !$this->shouldQueue);
        }

        $notificationId = Id::uuid();

        if (!$this->shouldQueue) {
            (new NotificationDispatcher($this->notifiable, $this->notification, $channels, $notificationId))->handle();

            return null;
        }

        foreach ($channels as $channel) {
            $job = new NotificationDispatcher($this->notifiable, $this->notification, [$channel], $notificationId);

            if ($delay > 0) {
                $job->delayFor($delay)->forceQueue();
            } else {
                $job->dispatch();
            }
        }

        return $notificationId;
    }

    /**
     * Keep the channels the notifiable wants to receive this notification on
     *
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    protected function allowedChannels(array $channels): array
    {
        if (!is_object($this->notifiable) || !method_exists($this->notifiable, 'wantsNotification')) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn ($channel): bool => (bool) $this->notifiable->wantsNotification($this->notification, (string) $channel)
        ));
    }

    /**
     *
     * Automatically sends the notification when the object is destroyed,
     * but only if all of the following conditions are met:
     *  - The notification has not already been sent and
     *  - No specific channels were set and
     *  - No delay was set and
     *  - The notification is intended to be queued
     */
    public function __destruct()
    {
        if (
            !$this->sent &&
            $this->channels === null &&
            $this->delay === 0 &&
            $this->shouldQueue
        ) {
            $this->send();
        }
    }
}
