<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Cache-busting URLs for static files.
 *
 * .htaccess marks everything under assets/ as immutable for a year, so the URL
 * has to change when the file does.
 *
 * The version goes in a **path segment**, not a query string:
 *
 *     /assets/v1a2b3c4d/js/app.js
 *
 * and .htaccess rewrites that segment away again. The reason is that two kinds
 * of reference are resolved by the browser relative to the file that contains
 * them, and neither passes through this class:
 *
 *   * `import './serializer.js'` inside a JavaScript module, and
 *   * `url(../vendor/…/ubuntu.woff2)` inside a stylesheet.
 *
 * With a query string those sub-resources are requested at an unversioned URL,
 * so a deploy leaves a visitor with a year-old cached copy of every lazily
 * imported module — new entry point, stale editor. It also meant the font was
 * fetched twice: once at the preload's `?v=…` URL and once at the bare URL the
 * stylesheet asked for.
 *
 * A path segment is inherited by relative references for free, which fixes
 * both. The cost is that the version covers the whole tree, so any change to
 * any asset re-validates all of them. For a site with well under a megabyte of
 * assets that is a good trade for never serving a mismatched pair.
 */
final class Assets
{
    /** @var array<string,string> relative path => content hash */
    private static array $versions = [];

    private static bool $manifestLoaded = false;

    private static ?string $tree = null;

    public static function url(string $file): string
    {
        $file = ltrim($file, '/');
        $relative = str_starts_with($file, 'assets/') ? substr($file, strlen('assets/')) : $file;

        return path('/assets/v' . self::treeVersion() . '/' . $relative);
    }

    /**
     * One version for the whole assets tree.
     *
     * Taken from the manifest when there is one, which is the deployed case and
     * costs a single file read. Without a manifest it walks the tree, which is
     * only what happens in development.
     */
    public static function treeVersion(): string
    {
        if (self::$tree !== null) {
            return self::$tree;
        }

        self::loadManifest();

        if (self::$versions !== []) {
            return self::$tree = substr(hash('xxh3', implode('|', self::$versions)), 0, 8);
        }

        $root = MTL_ROOT . '/assets';

        if (!is_dir($root)) {
            return self::$tree = 'dev';
        }

        $parts = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $parts[] = $file->getPathname() . '|' . $file->getMTime() . '|' . $file->getSize();
            }
        }

        sort($parts);

        return self::$tree = substr(hash('xxh3', implode("\n", $parts)), 0, 8);
    }

    /**
     * Strips the version segment from a request path.
     *
     * Production does this in .htaccess; the development server has no rewrite
     * engine and calls this from router.php instead.
     */
    public static function stripVersion(string $path): string
    {
        return (string) preg_replace('#^/assets/v[0-9a-z]+/#', '/assets/', $path);
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
        self::$tree = null;

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
