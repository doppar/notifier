<?php

namespace Doppar\Notifier\Channels;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\Support\Http;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;

class DiscordChannel extends ChannelDriver
{
    /**
     * Send notification to Discord
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    public function send($notifiable, Notification $notification): void
    {
        $webhookUrl = $notifiable->routeNotificationFor('discord');

        if (!$webhookUrl) {
            throw new \RuntimeException('No Discord webhook URL defined for notifiable entity.');
        }

        $content = $notification->contentFor('discord', $notifiable);

        $payload = [
            'content' => $content['content'] ?? '',
            'username' => $content['username'] ?? $this->config('notification.discord.username', 'Doppar Bot'),
            'avatar_url' => $content['avatar'] ?? null,
            'embeds' => $content['embeds'] ?? [],
        ];

        $this->postToDiscord((string) $webhookUrl, $payload);
    }

    /**
     * Send payload to Discord webhook
     *
     * @param string $webhookUrl
     * @param array $payload
     * @return void
     * @throws \RuntimeException
     */
    protected function postToDiscord(string $webhookUrl, array $payload): void
    {
        $response = Http::post(
            $webhookUrl,
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['Content-Type: application/json']
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Discord notification failed with HTTP code: {$response['status']}");
        }
    }
}
