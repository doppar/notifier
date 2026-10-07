<?php

namespace Doppar\Notifier\Channels\Contracts;

use Phaseolies\Application;
use Doppar\Notifier\Contracts\Notification;

abstract class ChannelDriver
{
    /**
     * Application container instance
     *
     * @var mixed
     */
    protected Application $app;

    /**
     * Create a new channel driver instance
     *
     * @param mixed $app
     */
    public function __construct($app = null)
    {
        if ($app instanceof Application) {
            $this->app = $app;
        }
    }

    /**
     * Send the notification through this channel
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    abstract public function send($notifiable, Notification $notification): void;

    /**
     * Get configuration value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function config(string $key, $default = null)
    {
        if (function_exists('config')) {
            try {
                return config($key, $default);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }
}
