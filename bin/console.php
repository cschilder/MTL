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

/*
 * Keep the web out — but only the web.
 *
 * Testing PHP_SAPI === 'cli' looks like the obvious guard, and it locked Strato
 * users out of their own shell: the `php` on Strato's SSH is the CGI binary,
 * whose SAPI reports cgi-fcgi even when invoked from a command line. What
 * actually distinguishes a web request is the CGI environment a web server
 * sets — a request method, a client address, a host header. A shell invocation
 * has none of those, whichever binary it runs.
 */
if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403);
    echo "bin/console.php can only be run from the command line.\n";
    exit(1);
}

/*
 * The CLI SAPI defines the standard streams; the CGI binary does not, and the
 * Output class and the interactive prompts depend on them.
 */
defined('STDIN') || define('STDIN', fopen('php://stdin', 'r'));
defined('STDOUT') || define('STDOUT', fopen('php://stdout', 'w'));
defined('STDERR') || define('STDERR', fopen('php://stderr', 'w'));

/*
 * Under CGI the argument array only exists when register_argc_argv is on.
 * Without it there is no way to know which command was asked for, so say
 * exactly how to pass it rather than showing the help as if nothing was typed.
 */
$arguments = $argv ?? $_SERVER['argv'] ?? null;

if (!is_array($arguments)) {
    fwrite(STDERR, "PHP did not pass the command line arguments through (register_argc_argv is off).\n");
    fwrite(STDERR, "Run it as:  php -d register_argc_argv=1 bin/console.php <command>\n");
    exit(1);
}

/*
 * The CGI binary prints an HTTP header block before its first output. That
 * cannot be fully suppressed from inside the script — its -q flag exists for
 * exactly that — but clearing the default headers keeps the noise down.
 */
if (PHP_SAPI !== 'cli') {
    header_remove();
}

/*
 * Session and cookie code keys off this rather than off PHP_SAPI: under
 * Strato's shell the SAPI is cgi-fcgi even for a console run, and starting a
 * real session or sending a cookie from one is never right.
 */
define('MTL_CONSOLE', true);

define('MTL_ROOT', dirname(__DIR__));

require MTL_ROOT . '/app/bootstrap.php';

use MTL\Console\ConsoleKernel;

exit((new ConsoleKernel())->run(array_slice($arguments, 1)));
