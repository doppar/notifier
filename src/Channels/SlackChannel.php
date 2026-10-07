<?php

namespace Doppar\Notifier\Channels;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\Support\Http;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;

class SlackChannel extends ChannelDriver
{
    /**
     * Send notification to Slack
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    public function send($notifiable, Notification $notification): void
    {
        $webhookUrl = $notifiable->routeNotificationFor('slack');

        if (!$webhookUrl) {
            throw new \RuntimeException('Slack webhook URL is missing.');
        }

        $content = $notification->contentFor('slack', $notifiable);

        $payload = [
            'text' => $content['text'] ?? '',
            'username' => $content['username'] ?? 'Doppar Bot',
            'icon_emoji' => $content['icon'] ?? ':bell:'
        ];

        if (isset($content['attachments']) && is_array($content['attachments'])) {
            $payload['attachments'] = $content['attachments'];
        }

        if (isset($content['blocks']) && is_array($content['blocks'])) {
            $payload['blocks'] = $content['blocks'];
        }

        if (isset($content['channel'])) {
            $payload['channel'] = $content['channel'];
        }

        $this->postToSlack((string) $webhookUrl, $payload);
    }

    /**
     * Send payload to Slack webhook
     *
     * @param string $webhookUrl
     * @param array $payload
     * @return void
     * @throws \RuntimeException
     */
    protected function postToSlack(string $webhookUrl, array $payload): void
    {
        $response = Http::post(
            $webhookUrl,
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['Content-Type: application/json']
        );

        if ($response['status'] !== 200 || trim($response['body']) !== 'ok') {
            throw new \RuntimeException(
                "Slack notification failed (HTTP {$response['status']}): " . ($response['body'] ?: $response['error'])
            );
        }
    }
}
