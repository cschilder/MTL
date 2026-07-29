<?php
/**
 * Shared bootstrap for the web front controller, the CLI console and the test
 * runner. It registers the autoloader and the procedural helpers but does not
 * touch the database, the session or output — Application::boot() does that.
 */

declare(strict_types=1);

if (!defined('MTL_ROOT')) {
    define('MTL_ROOT', dirname(__DIR__));
}

// Marks a legitimate entry point. Every file under app/ refuses to execute
// without it, so a misconfigured host that serves app/*.php directly still
// produces a blank page rather than running application code out of context.
define('MTL_APP', true);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('MTL requires PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

require MTL_ROOT . '/app/Support/helpers.php';
require MTL_ROOT . '/app/Core/Autoloader.php';

MTL\Core\Autoloader::register([
    'MTL\\' => MTL_ROOT . '/app',
]);
