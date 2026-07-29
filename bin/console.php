<?php
/**
 * MTL command line tool.
 *
 *   php bin/console.php <command> [options]
 *
 * Strato's Hosting Advanced package does not offer SSH on every plan, so
 * everything here is also reachable from the management environment under
 * Maintenance. The console exists for local development, for CI, and for the
 * plans that do have a shell.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/console.php can only be run from the command line.\n");
}

define('MTL_ROOT', dirname(__DIR__));

require MTL_ROOT . '/app/bootstrap.php';

use MTL\Console\ConsoleKernel;

exit((new ConsoleKernel())->run(array_slice($argv, 1)));
