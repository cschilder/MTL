<?php
/**
 * MTL front controller.
 *
 * Apache rewrites every request that is not an existing file to this script
 * (see .htaccess). On a host without mod_rewrite the application still works
 * through index.php?/path/to/page, which Request::path() understands.
 */

declare(strict_types=1);

define('MTL_START', microtime(true));
define('MTL_ROOT', __DIR__);

require __DIR__ . '/app/bootstrap.php';

MTL\Core\Application::boot(MTL_ROOT)->run();
