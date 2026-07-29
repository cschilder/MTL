<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Cache-busting URLs for static files.
 *
 * .htaccess marks everything under assets/ as immutable for a year, so the URL
 * has to change when the file does. A short content hash in the query string
 * does that without renaming files, which keeps FTP deployment simple.
 */
final class Assets
{
    /** @var array<string,string> relative path => version string */
    private static array $versions = [];

    private static bool $manifestLoaded = false;

    public static function url(string $file): string
    {
        $file = ltrim($file, '/');
        $relative = str_starts_with($file, 'assets/') ? $file : 'assets/' . $file;

        return path('/' . $relative, ['v' => self::version($relative)]);
    }

    private static function version(string $relative): string
    {
        if (isset(self::$versions[$relative])) {
            return self::$versions[$relative];
        }

        self::loadManifest();

        if (isset(self::$versions[$relative])) {
            return self::$versions[$relative];
        }

        $absolute = MTL_ROOT . '/' . $relative;

        // Fall back to the modification time. It changes on every upload,
        // which is exactly what a deployment does.
        $version = is_file($absolute)
            ? substr(hash('xxh3', (string) filemtime($absolute) . '|' . (string) filesize($absolute)), 0, 8)
            : 'missing';

        return self::$versions[$relative] = $version;
    }

    /**
     * Reads storage/cache/assets.json, written by `php bin/console.php optimize`.
     * It holds true content hashes so two servers serving the same deploy
     * produce identical URLs.
     */
    private static function loadManifest(): void
    {
        if (self::$manifestLoaded) {
            return;
        }
        self::$manifestLoaded = true;

        $file = MTL_ROOT . '/storage/cache/assets.json';
        if (!is_file($file)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            /** @var array<string,string> $decoded */
            self::$versions = $decoded + self::$versions;
        }
    }

    /**
     * Hashes every file under assets/ and writes the manifest.
     *
     * @return int number of files hashed
     */
    public static function buildManifest(): int
    {
        $root = MTL_ROOT . '/assets';
        if (!is_dir($root)) {
            return 0;
        }

        $map = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relative = 'assets/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $map[$relative] = substr((string) hash_file('xxh3', $file->getPathname()), 0, 8);
        }

        ksort($map);

        $target = MTL_ROOT . '/storage/cache/assets.json';
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        file_put_contents($target, (string) json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        self::$versions = $map;
        self::$manifestLoaded = true;

        return count($map);
    }

    /**
     * Inlines a small file, used for the critical CSS that has to be in the
     * document before the first paint.
     */
    public static function inline(string $file): string
    {
        $absolute = MTL_ROOT . '/' . ltrim($file, '/');

        return is_file($absolute) ? (string) file_get_contents($absolute) : '';
    }
}
