<?php

namespace Doppar\Notifier\Channels\Support;

final class MailTemplate
{
    /**
     * Build the HTML and the plain text of a mail from its parts
     *
     * @param array<string, mixed> $content
     * @return array{html: string, text: string}
     * @throws \InvalidArgumentException
     */
    public static function render(array $content): array
    {
        $greeting = isset($content['greeting']) ? (string) $content['greeting'] : '';
        $lines = self::strings($content['lines'] ?? []);
        $outro = self::strings($content['outro'] ?? []);
        $footer = isset($content['footer']) ? (string) $content['footer'] : '';
        $action = self::action($content['action'] ?? null);

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;max-width:560px;margin:0 auto;padding:24px">';

        if ($greeting !== '') {
            $html .= '<h2 style="margin:0 0 16px;font-size:20px">' . self::e($greeting) . '</h2>';
        }

        foreach ($lines as $line) {
            $html .= '<p style="margin:0 0 14px">' . self::e($line) . '</p>';
        }

        if ($action !== null) {
            $html .= '<p style="margin:22px 0"><a href="' . self::e($action['url']) . '" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">'
                . self::e($action['text']) . '</a></p>';
        }

        foreach ($outro as $line) {
            $html .= '<p style="margin:0 0 14px">' . self::e($line) . '</p>';
        }

        if ($footer !== '') {
            $html .= '<p style="margin:24px 0 0;font-size:12px;color:#6b7280">' . self::e($footer) . '</p>';
        }

        $html .= '</div>';

        $text = implode("\n\n", array_filter([
            $greeting,
            implode("\n\n", $lines),
            $action !== null ? $action['text'] . ': ' . $action['url'] : '',
            implode("\n\n", $outro),
            $footer,
        ], static fn (string $part): bool => $part !== ''));

        return ['html' => $html, 'text' => $text];
    }

    /**
     * Escape a value for HTML
     *
     * @param string $value
     * @return string
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Get a list of non-empty strings
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $items),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * Validate the action button
     *
     * @param mixed $action
     * @return array{text: string, url: string}|null
     * @throws \InvalidArgumentException
     */
    private static function action(mixed $action): ?array
    {
        if (!is_array($action) || empty($action['url'])) {
            return null;
        }

        $url = (string) $action['url'];

        if (!preg_match('#^https?://#i', $url) || preg_match('/[\s<>"\']/', $url)) {
            throw new \InvalidArgumentException('The mail action link must be a plain http(s) address.');
        }

        return ['text' => (string) ($action['text'] ?? 'Open'), 'url' => $url];
    }
}
