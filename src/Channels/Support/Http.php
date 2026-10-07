<?php

namespace Doppar\Notifier\Channels\Support;

final class Http
{
    /**
     * The transport used instead of cURL, set by tests
     *
     * @var (callable(string, string, array<int, string>, int): array{status: int, body: string, error: string})|null
     */
    private static $transport = null;

    /**
     * Replace the HTTP transport, or restore cURL with null
     *
     * @param callable|null $transport
     * @return void
     */
    public static function fake(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Send a POST request
     *
     * @param string $url
     * @param string $body
     * @param array<int, string> $headers
     * @param int $timeout
     * @return array{status: int, body: string, error: string}
     */
    public static function post(string $url, string $body, array $headers = [], int $timeout = 10): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $body, $headers, $timeout);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
            'error' => $error,
        ];
    }
}
