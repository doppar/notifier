<?php

namespace Doppar\Notifier\Channels;

use Doppar\Notifier\Contracts\Notification;
use Doppar\Notifier\Channels\Support\Http;
use Doppar\Notifier\Channels\Contracts\ChannelDriver;

class WebhookChannel extends ChannelDriver
{
    /**
     * Send the notification to a webhook as JSON
     *
     * The body is the notification's webhook content. A content with a payload key
     * sends only that, and its headers key adds request headers. When a secret is
     * set (content key secret, or notification.webhook.secret in the config) the
     * body is signed and the signature is sent in X-Notification-Signature as
     * sha256=<hmac>, so the receiver can check who sent it.
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     * @throws \RuntimeException
     */
    public function send($notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor('webhook');

        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Webhook URL is missing or is not an http(s) address.');
        }

        $content = $notification->contentFor('webhook', $notifiable);
        $body = json_encode(
            isset($content['payload']) && is_array($content['payload']) ? $content['payload'] : $content,
            JSON_THROW_ON_ERROR
        );

        $headers = ['Content-Type: application/json'];
        $custom = isset($content['headers']) && is_array($content['headers']) ? $content['headers'] : [];

        foreach ($custom as $name => $value) {
            $name = (string) $name;
            $line = $name . ': ' . (string) $value;

            if (preg_match('/[\r\n]/', $line) || strcasecmp($name, 'Content-Type') === 0) {
                continue;
            }

            $headers[] = $line;
        }

        $secret = $content['secret'] ?? $this->config('notification.webhook.secret');

        if (is_string($secret) && $secret !== '') {
            $headers[] = 'X-Notification-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = Http::post($url, $body, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                "Webhook notification failed (HTTP {$response['status']}): " . ($response['error'] ?: substr($response['body'], 0, 200))
            );
        }
    }
}
