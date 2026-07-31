<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Auth\AuthManager;
use MTL\Core\Database;

defined('MTL_APP') || exit;

/**
 * Site settings that an administrator can change from the management
 * environment, as opposed to the deployment settings in config/config.php.
 *
 * Autoloaded keys are fetched in a single query the first time any setting is
 * read; the rest are loaded on demand.
 */
final class SettingsService
{
    /** @var array<string,mixed>|null */
    private static ?array $autoloaded = null;

    /** @var array<string,mixed> */
    private static array $resolved = [];

    /**
     * Defaults for a fresh installation. A key absent from the table falls
     * back to the value here, so the site works before anything is configured.
     *
     * @var array<string,array{value:mixed,type:string,group:string,autoload:bool}>
     */
    private const DEFAULTS = [
        'site.title'            => ['value' => 'MTL',                        'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.tagline'          => ['value' => 'Reisverhalen van de wereld', 'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.description'      => ['value' => '',                           'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.owner_name'       => ['value' => '',                           'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.language'         => ['value' => 'nl',                         'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.timezone'         => ['value' => 'Europe/Amsterdam',           'type' => 'string', 'group' => 'general', 'autoload' => true],
        'site.public'           => ['value' => true,                         'type' => 'bool',   'group' => 'general', 'autoload' => true],
        'site.logo_media_id'    => ['value' => 0,                            'type' => 'int',    'group' => 'general', 'autoload' => true],
        'site.theme'            => ['value' => 'auto',                       'type' => 'string', 'group' => 'appearance', 'autoload' => true],
        'site.accent'           => ['value' => '#0f7d5c',                    'type' => 'string', 'group' => 'appearance', 'autoload' => true],

        'globe.default_view'    => ['value' => 'globe',                      'type' => 'string', 'group' => 'globe', 'autoload' => true],
        'globe.auto_rotate'     => ['value' => true,                         'type' => 'bool',   'group' => 'globe', 'autoload' => true],
        'globe.show_graticule'  => ['value' => true,                         'type' => 'bool',   'group' => 'globe', 'autoload' => true],
        'globe.show_terminator' => ['value' => true,                         'type' => 'bool',   'group' => 'globe', 'autoload' => true],
        'globe.marker_scale'    => ['value' => 1.0,                          'type' => 'float',  'group' => 'globe', 'autoload' => true],
        'globe.resolution'      => ['value' => 'low',                        'type' => 'string', 'group' => 'globe', 'autoload' => true],

        'media.default_visibility' => ['value' => 'inherit',                 'type' => 'string', 'group' => 'media', 'autoload' => true],
        'media.strip_gps'          => ['value' => false,                     'type' => 'bool',   'group' => 'media', 'autoload' => true],
        'media.download_original'  => ['value' => false,                     'type' => 'bool',   'group' => 'media', 'autoload' => true],
        'media.quota_mb'           => ['value' => 0,                         'type' => 'int',    'group' => 'media', 'autoload' => true],

        'registration.open'     => ['value' => false,                        'type' => 'bool',   'group' => 'users', 'autoload' => true],
        'registration.default_role' => ['value' => 'viewer',                 'type' => 'string', 'group' => 'users', 'autoload' => true],

        // The Android wrapper. Filled in after the APK is signed; until then
        // /.well-known/assetlinks.json correctly reports that no app is
        // associated with this domain.
        'android.package_name'         => ['value' => 'space.r010.mtl',      'type' => 'string', 'group' => 'android', 'autoload' => false],
        'android.sha256_fingerprints'  => ['value' => '',                    'type' => 'string', 'group' => 'android', 'autoload' => false],

        'maintenance.enabled'   => ['value' => false,                        'type' => 'bool',   'group' => 'maintenance', 'autoload' => true],
        'maintenance.message'   => ['value' => '',                           'type' => 'string', 'group' => 'maintenance', 'autoload' => true],
        'maintenance.last_run'  => ['value' => '',                           'type' => 'string', 'group' => 'maintenance', 'autoload' => false],
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        self::loadAutoloaded();

        if (self::$autoloaded !== null && array_key_exists($key, self::$autoloaded)) {
            return self::$resolved[$key] = self::$autoloaded[$key];
        }

        // Not autoloaded: one targeted read.
        try {
            $row = Database::instance()->table('settings')->where('key', '=', $key)->first();
        } catch (\Throwable) {
            // Before the schema exists (during install), fall back to defaults.
            $row = null;
        }

        if ($row !== null) {
            return self::$resolved[$key] = self::cast($row['value'], (string) $row['type']);
        }

        if (isset(self::DEFAULTS[$key])) {
            return self::$resolved[$key] = self::DEFAULTS[$key]['value'];
        }

        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function set(string $key, mixed $value, ?string $type = null, string $group = 'general', bool $autoload = true): void
    {
        $type ??= self::DEFAULTS[$key]['type'] ?? self::inferType($value);
        $group = self::DEFAULTS[$key]['group'] ?? $group;
        $autoload = self::DEFAULTS[$key]['autoload'] ?? $autoload;

        Database::instance()->table('settings')->upsert([
            'key'        => $key,
            'value'      => self::serialise($value, $type),
            'type'       => $type,
            'group'      => $group,
            'autoload'   => $autoload ? 1 : 0,
            'updated_by' => AuthManager::instance()->id(),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['value', 'type', 'group', 'autoload', 'updated_by', 'updated_at']);

        self::$resolved[$key] = self::cast(self::serialise($value, $type), $type);

        if (self::$autoloaded !== null && $autoload) {
            self::$autoloaded[$key] = self::$resolved[$key];
        }
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function setMany(array $values): void
    {
        Database::instance()->transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::set($key, $value);
            }
        });

        AuditService::log('settings.updated', null, ['keys' => array_keys($values)]);
    }

    /**
     * Every setting in a group, merged over the defaults so a screen can
     * render before anything has been saved.
     *
     * @return array<string,mixed>
     */
    public static function group(string $group): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $definition) {
            if ($definition['group'] === $group) {
                $out[$key] = self::get($key);
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::DEFAULTS as $definition) {
            $groups[$definition['group']] = true;
        }

        return array_keys($groups);
    }

    private static function loadAutoloaded(): void
    {
        if (self::$autoloaded !== null) {
            return;
        }

        self::$autoloaded = [];

        try {
            $rows = Database::instance()->table('settings')->where('autoload', '=', 1)->get();
        } catch (\Throwable) {
            // No schema yet; defaults will be used.
            return;
        }

        foreach ($rows as $row) {
            self::$autoloaded[(string) $row['key']] = self::cast($row['value'], (string) $row['type']);
        }

        // Defaults fill the gaps for keys never written.
        foreach (self::DEFAULTS as $key => $definition) {
            if ($definition['autoload'] && !array_key_exists($key, self::$autoloaded)) {
                self::$autoloaded[$key] = $definition['value'];
            }
        }
    }

    /** Drops the in-process cache, needed after a bulk write. */
    public static function flush(): void
    {
        self::$autoloaded = null;
        self::$resolved = [];
    }

    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            'json'  => json_decode((string) $value, true) ?? [],
            default => (string) $value,
        };
    }

    private static function serialise(mixed $value, string $type): string
    {
        return match ($type) {
            'bool'  => $value ? '1' : '0',
            'json'  => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    private static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => 'string',
        };
    }
}
