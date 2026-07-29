<?php
/**
 * Router script for PHP's built-in development server.
 *
 *   php bin/console.php serve
 *   php -S 127.0.0.1:8000 router.php
 *
 * The built-in server has no rewrite engine, so this reproduces the two rules
 * that matter from .htaccess: serve a real file if one exists, otherwise hand
 * the request to the front controller. It is never used on Strato.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

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
    // Returning false lets the built-in server serve the file itself, with the
    // right content type.
    return false;
}

require __DIR__ . '/index.php';
