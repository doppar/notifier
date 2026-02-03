<?php

namespace Doppar\Notifier\Tests\Mock\Notifications;

use Doppar\Notifier\Contracts\Notification;

class TestEmailNotification extends Notification
{
    public function __construct(
        public string $subject,
        public string $message
    ) {}

    /**
     * Define the notification channels
     *
     * @param mixed $notifiable
     * @return array
     */
    public function channels($notifiable): array
    {
        return ['database'];
    }

    /**
     * Define the notification content
     *
     * @param mixed $notifiable
     * @return array
     */
    public function content($notifiable): array
    {
        return [
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }

    /**
     * Get notification metadata
     *
     * @return array
     */
    public function metadata(): array
    {
        return [
            'type' => 'email',
            'priority' => 'normal',
        ];
    }
}
