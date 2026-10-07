<?php

namespace Doppar\Notifier\Support;

final class Notifiables
{
    /**
     * Get the key that identifies a notifiable entity
     *
     * @param mixed $notifiable
     * @return int|string|null
     */
    public static function key(mixed $notifiable): int|string|null
    {
        if (!is_object($notifiable)) {
            return null;
        }

        $key = method_exists($notifiable, 'getKey')
            ? $notifiable->getKey()
            : ($notifiable->id ?? null);

        return is_int($key) || (is_string($key) && $key !== '') ? $key : null;
    }

    /**
     * Read an attribute of a notifiable entity without failing when it has none
     *
     * Models keep their attributes behind magic accessors that isset() cannot see,
     * so they are asked for their attributes instead.
     *
     * @param mixed $notifiable
     * @param string $name
     * @return mixed
     */
    public static function attribute(mixed $notifiable, string $name): mixed
    {
        if (!is_object($notifiable)) {
            return null;
        }

        if (method_exists($notifiable, 'getAttributes')) {
            return array_key_exists($name, $notifiable->getAttributes()) ? $notifiable->{$name} : null;
        }

        return property_exists($notifiable, $name) ? $notifiable->{$name} : null;
    }

    /**
     * Get the key of a notifiable entity or fail when it has none
     *
     * @param mixed $notifiable
     * @return int|string
     * @throws \RuntimeException
     */
    public static function requireKey(mixed $notifiable): int|string
    {
        $key = self::key($notifiable);

        if ($key === null) {
            throw new \RuntimeException(
                'The notifiable has no key. Save it before sending it a notification.'
            );
        }

        return $key;
    }

    /**
     * Get the type stored for a notifiable entity
     *
     * @param mixed $notifiable
     * @return string
     */
    public static function type(mixed $notifiable): string
    {
        return is_object($notifiable) ? get_class($notifiable) : gettype($notifiable);
    }
}
