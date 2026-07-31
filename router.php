<?php
/**
 * Router script for PHP's built-in development server.
 *
 *   php bin/console.php serve
 *   php -S 127.0.0.1:8000 router.php
 *
 * The built-in server has no rewrite engine, so this reproduces the three rules
 * that matter from .htaccess: strip the version segment from an asset URL,
 * serve a real file if one exists, and otherwise hand the request to the front
 * controller. It is never used on Strato.
 */

declare(strict_types=1);

$requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requested = is_string($requested) ? rawurldecode($requested) : '/';

// /assets/v1a2b3c4d/js/app.js -> /assets/js/app.js
$path = (string) preg_replace('#^/assets/v[0-9a-z]+/#', '/assets/', $requested);

$file = __DIR__ . $path;

// Refuse the directories .htaccess protects, so development behaves like
// production rather than happily serving config/config.php.
foreach (['/app/', '/config/', '/db/', '/bin/', '/tests/', '/tools/', '/android/', '/storage/'] as $blocked) {
    if (str_starts_with($path, $blocked)) {
        http_response_code(404);
        exit;
    }
}

if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    if ($path === $requested) {
        // Returning false lets the built-in server serve the file itself, with
        // the right content type.
        return false;
    }

    // A versioned asset URL has to be served from here: `return false` makes the
    // built-in server serve the *original* request URI, which still contains the
    // version segment and therefore does not exist on disk.
    $types = [
        'avif' => 'image/avif',
        'bin' => 'application/octet-stream',
        'css' => 'text/css',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'map' => 'application/json',
        'mjs' => 'text/javascript',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file);
    exit;
}

require __DIR__ . '/index.php';
