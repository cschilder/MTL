<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Immutable-after-boot application configuration.
 *
 * config/config.php returns a nested array; everything else in the application
 * reads it through dot notation.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $file, ?string $fallback = null): void
    {
        if (!is_file($file)) {
            if ($fallback === null || !is_file($fallback)) {
                throw new \RuntimeException(
                    'No configuration found. Copy config/config.example.php to config/config.php and fill in the database credentials.'
                );
            }
            $file = $fallback;
        }

        /** @var array<string,mixed> $items */
        $items = require $file;

        if (!is_array($items)) {
            throw new \RuntimeException('Configuration file must return an array: ' . $file);
        }

        self::$items = $items;
        self::$loaded = true;
    }

    /**
     * @param array<string,mixed> $items
     */
    public static function set(array $items): void
    {
        self::$items = array_replace_recursive(self::$items, $items);
        self::$loaded = true;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    /**
     * True when the application should show diagnostics rather than a generic
     * error page.
     */
    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }

    public static function isProduction(): bool
    {
        return self::get('app.env', 'production') === 'production';
    }
}
