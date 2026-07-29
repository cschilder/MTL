<?php

declare(strict_types=1);

namespace MTL\Markdown;

use MTL\Core\Config;

defined('MTL_APP') || exit;

/**
 * URL vetting for links and images that come out of user-written markdown.
 *
 * The rule is a scheme allow-list rather than a block-list: a block-list has to
 * anticipate every dangerous scheme, and browsers keep adding them.
 */
final class Url
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp', 'geo'];

    public static function isSafe(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Control characters are stripped by browsers before the scheme is
        // read, so "java\tscript:" would slip past a naive check.
        $probe = strtolower(preg_replace('/[\x00-\x20\x7F]/', '', $url) ?? '');

        // A relative URL, an anchor or a protocol-relative URL has no scheme
        // of its own and inherits the page's, which is safe.
        if (str_starts_with($probe, '#') || str_starts_with($probe, '/') || str_starts_with($probe, '?')) {
            return true;
        }

        // MTL's own media reference form.
        if (str_starts_with($probe, 'mtl:media/')) {
            return true;
        }

        $colon = strpos($probe, ':');

        if ($colon === false) {
            // No scheme at all: a relative path such as "photos/x.jpg".
            return true;
        }

        // A colon that appears after a slash or a question mark belongs to the
        // path or query, not to a scheme.
        $slash = strpos($probe, '/');
        $question = strpos($probe, '?');

        if (($slash !== false && $slash < $colon) || ($question !== false && $question < $colon)) {
            return true;
        }

        $scheme = substr($probe, 0, $colon);

        return in_array($scheme, self::ALLOWED_SCHEMES, true);
    }

    /**
     * Cleans a URL for use in an attribute.
     */
    public static function normalise(string $url): string
    {
        $url = trim($url);

        // Remove characters that cannot legally appear and that are only ever
        // used to confuse a parser.
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? $url;

        return $url;
    }

    /**
     * True when the URL points somewhere other than this site.
     */
    public static function isExternal(string $url): bool
    {
        if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, '?')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            // mailto:, tel: and relative paths.
            return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
        }

        $ownHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);

        return $host !== $ownHost;
    }
}
