<?php

namespace Doppar\Notifier;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\SlackChannel;
use Doppar\Notifier\Channels\DiscordChannel;
use Doppar\Notifier\Channels\DatabaseChannel;
use Doppar\Notifier\Channels\MailChannel;
use Doppar\Notifier\Channels\WebhookChannel;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;

class NotificationManager
{
    /**
     * The channels that ship with the package
     *
     * @var array<int, string>
     */
    public const BUILT_IN = ['database', 'mail', 'slack', 'discord', 'webhook'];

    /**
     * Cached channel driver instances
     * 
     * @var array<string, ChannelDriver>
     */
    protected array $channels = [];

    /**
     * Custom driver class mappings
     * 
     * @var array<string, string>
     */
    protected array $customDrivers = [];

    /**
     * Application container instance
     * 
     * @var mixed
     */
    protected $app;

    /**
     * Create a new notification manager instance
     * 
     * @param mixed $app
     */
    public function __construct($app = null)
    {
        $this->app = $app;
    }

    /**
     * Get a notification channel driver
     * 
     * @param string $channel
     * @return ChannelDriver
     */
    public function channel(string $channel): ChannelDriver
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = $this->resolveChannel($channel);
        }

        return $this->channels[$channel];
    }

    /**
     * Resolve a channel driver instance
     * 
     * @param string $channel
     * @return ChannelDriver
     * @throws \InvalidArgumentException
     */
    protected function resolveChannel(string $channel): ChannelDriver
    {
        if (isset($this->customDrivers[$channel])) {
            $driverClass = $this->customDrivers[$channel];
            return new $driverClass($this->app);
        }

        return match($channel) {
            'database' => new DatabaseChannel($this->app),
            'mail' => new MailChannel($this->app),
            'slack' => new SlackChannel($this->app),
            'discord' => new DiscordChannel($this->app),
            'webhook' => new WebhookChannel($this->app),
            default => throw new \InvalidArgumentException("Notification channel [{$channel}] is not supported."),
        };
    }

    /**
     * Register a custom channel driver
     *
     * @param string $channel
     * @param string $driverClass
     * @return self
     */
    public function extend(string $channel, string $driverClass): self
    {
        if (!class_exists($driverClass)) {
            throw new \InvalidArgumentException("Driver class [{$driverClass}] does not exist.");
        }

        if (!is_subclass_of($driverClass, ChannelDriver::class)) {
            throw new \InvalidArgumentException("Driver class must extend ChannelDriver.");
        }

        $this->customDrivers[$channel] = $driverClass;

        unset($this->channels[$channel]);

        return $this;
    }

    /**
     * Check if a channel is registered
     *
     * @param string $channel
     * @return bool
     */
    public function hasChannel(string $channel): bool
    {
        return isset($this->customDrivers[$channel]) ||
               in_array($channel, self::BUILT_IN, true);
    }

    /**
     * Get all registered channel names
     *
     * @return array
     */
    public function getChannels(): array
    {
        return array_merge(self::BUILT_IN, array_keys($this->customDrivers));
    }

    /**
     * Clear all cached channel instances
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->channels = [];
    }

    /**
     * Send notification through specific channel
     * 
     * @param string $channel
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     */
    public function sendVia(string $channel, $notifiable, Notification $notification): void
    {
        $driver = $this->channel($channel);

        $driver->send($notifiable, $notification);
    }
}
