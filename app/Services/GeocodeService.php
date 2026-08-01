<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Config;

defined('MTL_APP') || exit;

/**
 * Turns a place name into coordinates, via OpenStreetMap's Nominatim.
 *
 * This is the one place the application talks to the outside world, and it is
 * worth being precise about why that is acceptable here and nowhere else. The
 * public site stays self-contained: geocoding runs server-side, only for
 * signed-in authors editing a stop, so no visitor's browser ever contacts a
 * third party. Results are cached on disk, both to be a good citizen towards a
 * free service and because "Edinburgh" resolves to the same place next month.
 *
 * Nominatim's usage policy asks for an identifying User-Agent and at most one
 * request per second; the cache, the per-user rate limit on the endpoint and
 * the single-shot lookup on save keep this application far inside that.
 *
 * The HTTP transport is swappable so the test suite never touches the network:
 * a test hands in a closure that returns canned JSON.
 */
final class GeocodeService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** How long a cached answer stays valid. Places rarely move. */
    private const CACHE_TTL = 30 * 24 * 3600;

    private const TIMEOUT_SECONDS = 4;

    /** @var (callable(string,array<int,string>):?string)|null */
    private static $transport = null;

    /** Test seam: replaces the HTTP fetch with a canned response. */
    public static function swapTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Looks a place up.
     *
     * @return list<array{name:string,display:string,latitude:float,longitude:float,country:string,type:string}>
     */
    public static function search(string $query, ?string $language = null, int $limit = 5): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (mb_strlen($query, 'UTF-8') < 2) {
            return [];
        }

        $query = mb_substr($query, 0, 200, 'UTF-8');
        $language ??= 'en';
        $limit = max(1, min(10, $limit));

        $cacheFile = self::cacheFile($query, $language, $limit);

        $cached = self::readCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'format'          => 'jsonv2',
            'q'               => $query,
            'limit'           => $limit,
            'addressdetails'  => 1,
            'accept-language' => $language,
        ], '', '&', PHP_QUERY_RFC3986);

        $body = self::fetch($url);

        if ($body === null) {
            // Unreachable is not "no results": nothing is cached, so the next
            // attempt tries the network again.
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [];
        }

        $results = [];

        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'])) {
                continue;
            }

            $results[] = [
                'name'      => (string) ($row['name'] ?? '') !== ''
                    ? (string) $row['name']
                    : (string) explode(',', (string) ($row['display_name'] ?? $query))[0],
                'display'   => (string) ($row['display_name'] ?? ''),
                'latitude'  => round((float) $row['lat'], 7),
                'longitude' => round((float) $row['lon'], 7),
                'country'   => strtoupper((string) ($row['address']['country_code'] ?? '')),
                'type'      => (string) ($row['type'] ?? ''),
            ];
        }

        // An empty answer is an answer: "Atlantis" will not exist tomorrow
        // either, and caching it keeps retries away from the service.
        self::writeCache($cacheFile, $results);

        return $results;
    }

    /**
     * The single best hit, used when a stop is saved with a place name and no
     * coordinates. Null when the world has no idea either.
     *
     * @return array{name:string,display:string,latitude:float,longitude:float,country:string,type:string}|null
     */
    public static function best(string $query, ?string $language = null): ?array
    {
        return self::search($query, $language, 1)[0] ?? null;
    }

    // -------------------------------------------------------------------------

    private static function fetch(string $url): ?string
    {
        // Nominatim requires an identifying agent; anonymous ones are blocked.
        $userAgent = 'MTL/1.0 (' . (string) Config::get('app.url', 'https://mtl.example') . ')';

        if (self::$transport !== null) {
            return (self::$transport)($url, ['User-Agent: ' . $userAgent]);
        }

        try {
            if (function_exists('curl_init')) {
                $handle = curl_init($url);

                if ($handle === false) {
                    return null;
                }

                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                    CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                    CURLOPT_USERAGENT      => $userAgent,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 2,
                ]);

                $body = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                return is_string($body) && $status === 200 ? $body : null;
            }

            // Without curl: PHP's stream layer. allow_url_fopen is on for the
            // hosts this application targets.
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'header'  => 'User-Agent: ' . $userAgent . "\r\n",
                ],
            ]);

            $body = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------

    private static function cacheFile(string $query, string $language, int $limit): string
    {
        $key = hash('sha256', mb_strtolower($query, 'UTF-8') . '|' . $language . '|' . $limit);

        return storage_path('cache/geocode/' . $key . '.json');
    }

    /**
     * @return list<array{name:string,display:string,latitude:float,longitude:float,country:string,type:string}>|null
     */
    private static function readCache(string $file): ?array
    {
        if (!is_file($file) || filemtime($file) < time() - self::CACHE_TTL) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    private static function writeCache(string $file, array $results): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            return;
        }

        @file_put_contents($file, (string) json_encode($results, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
