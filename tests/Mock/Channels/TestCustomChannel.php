<?php

namespace Doppar\Notifier\Tests\Mock\Channels;

use Doppar\Notifier\Channels\Contracts\ChannelDriver;
use Doppar\Notifier\Contracts\Notification;
use Phaseolies\Application;

class TestCustomChannel extends ChannelDriver
{
    /**
     * Track sent notifications
     *
     * @var array
     */
    public static array $sent = [];

    /**
     * Application container instance
     *
     * @var Application
     */
    protected Application $app;

    /**
     * Create a new channel driver instance
     * 
     * @param Application $app
     */
    public function __construct($app = null)
    {
        $this->app = $app;
    }

    /**
     * Send the notification through this channel
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     */
    public function send($notifiable, Notification $notification): void
    {
        $content = $notification->content($notifiable);

        self::$sent[] = [
            'notifiable' => $notifiable,
            'notification' => get_class($notification),
            'content' => $content,
            'timestamp' => time(),
        ];
    }

    /**
     * Clear sent notifications
     *
     * @return void
     */
    public static function clearSent(): void
    {
        self::$sent = [];
    }

    /**
     * Get count of sent notifications
     *
     * @return int
     */
    public static function getSentCount(): int
    {
        return count(self::$sent);
    }

    /**
     * Get all sent notifications
     *
     * @return array
     */
    public static function getSent(): array
    {
        return self::$sent;
    }
}
