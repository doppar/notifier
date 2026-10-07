<?php

namespace Doppar\Notifier;

use Phaseolies\Support\Facades\Log;
use Doppar\Queue\Job;
use Doppar\Queue\InteractsWithModelSerialization;
use Doppar\Queue\Dispatchable;
use Doppar\Queue\Attributes\Queueable;
use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Support\DeliveryLog;
use Doppar\Notifier\Support\Id;
use Doppar\Notifier\Support\Notifiables;

#[Queueable(tries: 3, retryAfter: 60, onQueue: 'notifications')]
class NotificationDispatcher extends Job
{
    use InteractsWithModelSerialization, Dispatchable;

    /**
     * Initialize the dispatcher with a notifiable, notification, and optional channels
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @param array $channels
     * @param string|null $notificationId
     */
    public function __construct(
        public mixed $notifiable,
        public Notification $notification,
        public array $channels = [],
        public ?string $notificationId = null
    ) {
        $this->notificationId ??= Id::uuid();
    }

    /**
     * Sends the notification across all specified channels
     *
     * @return void
     * @throws \Throwable
     */
    public function handle(): void
    {
        if (!$this->notification->shouldSend($this->notifiable)) {
            foreach ($this->channels as $channel) {
                $this->record((string) $channel, 'skipped');
            }

            return;
        }

        $failure = null;

        foreach ($this->channels as $channel) {
            try {
                $this->deliverVia((string) $channel);
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Logs the exception message when dispatch fails
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Notification dispatch failed: " . $exception->getMessage());
    }

    /**
     * Get the queue priority of the notification
     *
     * @return int
     */
    public function priority(): int
    {
        return max(-100, min(100, $this->notification->priority()));
    }

    /**
     * Get the queue connection of the notification
     *
     * @return string|null
     */
    public function connection(): ?string
    {
        return $this->notification->onConnection();
    }

    /**
     * Get the seconds to wait before each retry
     *
     * @return int|array<int, int>|null
     */
    public function backoff(): int|array|null
    {
        return $this->notification->backoff();
    }

    /**
     * Get the key that stops the same notification from being queued twice
     *
     * @return string|null
     */
    public function uniqueId(): ?string
    {
        $id = $this->notification->uniqueId($this->notifiable);

        if ($id === null) {
            return null;
        }

        return 'notification:' . md5(implode('|', [
            get_class($this->notification),
            Notifiables::type($this->notifiable),
            (string) Notifiables::key($this->notifiable),
            implode(',', $this->channels),
            $id,
        ]));
    }

    /**
     * Deliver the notification through one channel
     *
     * @param string $channel
     * @return void
     * @throws \Throwable
     */
    protected function deliverVia(string $channel): void
    {
        if (DeliveryLog::alreadySent((string) $this->notificationId, $channel)) {
            return;
        }

        if (!$this->notification->shouldSendVia($this->notifiable, $channel)) {
            $this->record($channel, 'skipped');

            return;
        }

        if ($this->throttled($channel)) {
            $this->record($channel, 'throttled');

            return;
        }

        if (!NotificationEvents::sending($this->notifiable, $this->notification, $channel)) {
            $this->record($channel, 'cancelled');

            return;
        }

        try {
            app(NotificationManager::class)
                ->channel($channel)
                ->send($this->notifiable, $this->notification);
        } catch (\Throwable $e) {
            $this->record($channel, 'failed', $e->getMessage());
            NotificationEvents::failed($this->notifiable, $this->notification, $channel, $e);

            throw $e;
        }

        $this->record($channel, 'sent');
        NotificationEvents::sent($this->notifiable, $this->notification, $channel);
    }

    /**
     * Check if the notification reached its limit on a channel
     *
     * @param string $channel
     * @return bool
     */
    protected function throttled(string $channel): bool
    {
        $rule = $this->notification->throttle();

        if (!is_array($rule) || empty($rule['max']) || empty($rule['per'])) {
            return false;
        }

        return DeliveryLog::countSent(
            $this->notifiable,
            get_class($this->notification),
            $channel,
            time() - (int) $rule['per']
        ) >= (int) $rule['max'];
    }

    /**
     * Write what happened on a channel to the delivery log
     *
     * @param string $channel
     * @param string $status
     * @param string|null $error
     * @return void
     */
    protected function record(string $channel, string $status, ?string $error = null): void
    {
        DeliveryLog::record((string) $this->notificationId, $channel, $this->notifiable, $this->notification, $status, $error);
    }
}
