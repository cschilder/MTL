<?php

declare(strict_types=1);

namespace MTL\Support;

defined('MTL_APP') || exit;

/**
 * String helpers used across the application.
 */
final class Str
{
    /**
     * Transliteration table for the accented characters that actually turn up
     * in place names on a European travel site. iconv's //TRANSLIT is locale
     * dependent and silently produces '?' on some builds, so the common cases
     * are handled explicitly and iconv is only a fallback.
     *
     * @var array<string,string>
     */
    private const TRANSLITERATIONS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŋ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ŷ' => 'y', 'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'ъ' => '', 'ь' => '',
        'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th',
        'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p',
        'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',
    ];

    /**
     * Builds a URL-safe slug.
     *
     * Accented and Cyrillic characters are transliterated rather than dropped,
     * so "Þingvellir" becomes "thingvellir" and not "ingvellir".
     */
    public static function slug(string $value, int $maxLength = 96): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::TRANSLITERATIONS);

        // Anything still outside ASCII after transliteration: give iconv a try
        // before falling back to dropping it.
        if (preg_match('//u', $value) === 1 && preg_match('/[^\x00-\x7F]/', $value) === 1) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = strtolower($converted);
            }
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
            // Do not end on a half-word.
            $lastDash = strrpos($value, '-');
            if ($lastDash !== false && $lastDash > $maxLength * 0.6) {
                $value = substr($value, 0, $lastDash);
            }
            $value = trim($value, '-');
        }

        return $value;
    }

    /**
     * Truncates on a word boundary and appends an ellipsis.
     */
    public static function excerpt(string $value, int $length = 200, string $suffix = '…'): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        $cut = mb_substr($value, 0, $length, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');

        if ($lastSpace !== false && $lastSpace > $length * 0.5) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:") . $suffix;
    }

    /**
     * Strips markdown syntax to produce a plain-text summary for meta
     * descriptions and search indexing.
     */
    public static function stripMarkdown(string $markdown): string
    {
        $text = $markdown;

        // Fenced code blocks and inline code become their contents.
        $text = preg_replace('/```[a-zA-Z0-9+#-]*\n(.*?)```/s', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;

        // Images vanish, links keep their label.
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;

        // Leading block markers and inline emphasis.
        $text = preg_replace('/^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|~~)/', '', $text) ?? $text;
        $text = preg_replace('/^\s*([-*_]\s*){3,}$/m', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** A cryptographically random, URL-safe identifier. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * A short, human-typeable code without characters that look alike, used
     * for share links and invitation codes.
     */
    public static function randomCode(int $length = 10): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_starts_with($haystack, $needle);
    }

    /** Formats a byte count for display. */
    public static function bytes(int $bytes, int $precision = 1): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            ++$index;
        }

        return number_format($value, $precision, ',', '.') . ' ' . $units[$index];
    }

    /** Formats a duration in seconds as h:mm:ss or m:ss. */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    /** Normalises line endings and strips a UTF-8 BOM. */
    public static function normaliseText(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Strip control characters that cannot appear in text but can confuse
        // a parser or a terminal, keeping tab and newline.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    }

    /**
     * True when the string is valid UTF-8. Uploaded metadata and EXIF fields
     * frequently are not, and storing them would break the JSON encoder.
     */
    public static function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /** Forces a string into valid UTF-8, replacing anything undecodable. */
    public static function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');

        return is_string($converted) ? $converted : '';
    }

    /** Initials for an avatar placeholder. */
    public static function initials(string $name, int $max = 2): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= $max) {
                break;
            }
        }

        return $initials === '' ? '?' : $initials;
    }
}
