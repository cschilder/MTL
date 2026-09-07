<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Interface translations.
 *
 * Language files under app/Lang return a flat map of key => text. A missing
 * key falls back to the default locale and then to the key itself, so a
 * half-translated file degrades to English rather than to blanks.
 */
final class Translator
{
    private const FALLBACK = 'en';

    private static string $locale = 'nl';

    /** @var array<string,array<string,string>> locale => messages */
    private static array $messages = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) === 1 ? $locale : 'nl';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @return list<string> locales with a language file present */
    public static function available(): array
    {
        $files = glob(MTL_ROOT . '/app/Lang/*.php') ?: [];

        return array_values(array_map(
            static fn (string $f): string => basename($f, '.php'),
            $files
        ));
    }

    /**
     * @param array<string,scalar> $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $text = self::lookup(self::$locale, $key)
            ?? self::lookup(self::FALLBACK, $key)
            ?? $key;

        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Picks a singular or plural form: 'items' => 'one item|:count items'.
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        $text = self::get($key, $replace + ['count' => $count]);
        $forms = explode('|', $text);

        $form = $count === 1 ? ($forms[0] ?? $text) : ($forms[1] ?? $forms[0] ?? $text);

        return str_replace(':count', (string) $count, $form);
    }

    private static function lookup(string $locale, string $key): ?string
    {
        if (!isset(self::$messages[$locale])) {
            $file = MTL_ROOT . '/app/Lang/' . preg_replace('/[^a-zA-Z-]/', '', $locale) . '.php';

            /** @var array<string,string> $messages */
            $messages = is_file($file) ? (array) require $file : [];
            self::$messages[$locale] = $messages;
        }

        $value = self::$messages[$locale][$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The subset of strings the front end needs, injected into the page as
     * JSON so the editor and globe can label their own controls.
     *
     * @return array<string,string>
     */
    public static function forJavaScript(): array
    {
        self::lookup(self::$locale, '');
        self::lookup(self::FALLBACK, '');

        $merged = array_merge(self::$messages[self::FALLBACK] ?? [], self::$messages[self::$locale] ?? []);

        // A few strings are shared between server-rendered pages and the
        // modules; without them the browser showed the raw key ("app.saved")
        // in the editor's status line.
        $shared = ['app.saved', 'media.uploading', 'media.upload_failed'];

        return array_filter(
            $merged,
            static fn (string $key): bool => str_starts_with($key, 'js.') || in_array($key, $shared, true),
            ARRAY_FILTER_USE_KEY
        );
    }
}
