<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Core\Migrator;

defined('MTL_APP') || exit;

/**
 * The state machine behind the web installer.
 *
 * "Installed" means: the schema exists and at least one account does. That is
 * established once and then remembered in a lock file, because the question is
 * asked on every request and must not cost a query — and because the lock is
 * what keeps /install closed afterwards.
 *
 * Everything here is also idempotent. An installer that breaks halfway is
 * re-run, not debugged, so every step has to be safe to repeat.
 */
final class InstallService
{
    /** Directories the application writes into; created when missing. */
    private const WRITABLE_DIRECTORIES = [
        'storage',
        'storage/cache',
        'storage/logs',
        'storage/media',
        'storage/tmp',
    ];

    /**
     * Directories that must never be served, each holding its own deny rule as
     * the second lock behind the rules in the root .htaccess. FTP clients
     * routinely skip dotfiles, so the installer recreates any that went
     * missing in transit.
     */
    private const PROTECTED_DIRECTORIES = ['app', 'bin', 'config', 'db', 'storage', 'tests', 'tools'];

    private static ?bool $installed = null;

    // -------------------------------------------------------------------------
    // Installed or not
    // -------------------------------------------------------------------------

    public static function isInstalled(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        if (is_file(self::lockFile())) {
            return self::$installed = true;
        }

        // No lock. The truth lives in the database: a site updated by git pull
        // has no lock file yet but is very much installed, so the answer
        // self-heals into a lock rather than bouncing every visitor to the
        // installer.
        try {
            $hasUsers = Database::instance()->table('users')->exists();
        } catch (\Throwable) {
            return self::$installed = false;
        }

        if ($hasUsers) {
            self::writeLock();

            return self::$installed = true;
        }

        return self::$installed = false;
    }

    public static function writeLock(): void
    {
        $file = self::lockFile();

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        @file_put_contents(
            $file,
            "MTL was installed on " . gmdate('Y-m-d H:i:s') . " UTC.\n"
            . "Delete this file and every account to re-open /install.\n",
            LOCK_EX
        );

        self::$installed = true;
    }

    /** Testing seam: forgets the memoised answer and, optionally, the lock. */
    public static function reset(bool $removeLock = false): void
    {
        self::$installed = null;

        if ($removeLock && is_file(self::lockFile())) {
            @unlink(self::lockFile());
        }
    }

    private static function lockFile(): string
    {
        return MTL_ROOT . '/storage/cache/installed.lock';
    }

    // -------------------------------------------------------------------------
    // Repairs
    // -------------------------------------------------------------------------

    /**
     * Fixes what can be fixed without asking: missing writable directories,
     * missing deny rules, an empty application key.
     *
     * @return list<string> human-readable descriptions of what was done
     */
    public static function repair(): array
    {
        $done = [];

        foreach (self::WRITABLE_DIRECTORIES as $relative) {
            $path = MTL_ROOT . '/' . $relative;

            if (!is_dir($path) && @mkdir($path, 0775, true)) {
                $done[] = __('install.fixed_directory', ['path' => $relative . '/']);
            }
        }

        foreach (self::PROTECTED_DIRECTORIES as $relative) {
            $directory = MTL_ROOT . '/' . $relative;
            $file = $directory . '/.htaccess';

            if (is_dir($directory) && !is_file($file)
                && @file_put_contents($file, "Require all denied\n", LOCK_EX) !== false
            ) {
                $done[] = __('install.fixed_htaccess', ['path' => $relative . '/.htaccess']);
            }
        }

        if (self::generateMissingKey()) {
            $done[] = __('install.fixed_key');
        }

        return $done;
    }

    /**
     * Writes a fresh APP_KEY into config/config.php when the configured one is
     * empty. Only the literal empty-string spelling is touched; anything else
     * in the file stays exactly as the owner wrote it.
     */
    private static function generateMissingKey(): bool
    {
        if ((string) Config::get('app.key', '') !== '') {
            return false;
        }

        $file = MTL_ROOT . '/config/config.php';

        if (!is_file($file) || !is_writable($file)) {
            return false;
        }

        $source = (string) file_get_contents($file);
        $key = base64_encode(random_bytes(32));

        $patched = preg_replace(
            "/('key'\s*=>\s*)(''|\"\")/",
            "\$1'" . $key . "'",
            $source,
            1,
            $count
        );

        if ($patched === null || $count !== 1) {
            return false;
        }

        if (@file_put_contents($file, $patched, LOCK_EX) === false) {
            return false;
        }

        Config::set(['app' => ['key' => $key]]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Checks
    // -------------------------------------------------------------------------

    /**
     * The environment, as a list the wizard can render.
     *
     * @return list<array{label:string,ok:bool,required:bool,detail:string}>
     */
    public static function checks(): array
    {
        $checks = [];

        $add = static function (string $label, bool $ok, bool $required, string $detail = '') use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };

        $add(__('install.check_php'), PHP_VERSION_ID >= 80100, true, PHP_VERSION);
        $add('pdo_mysql', extension_loaded('pdo_mysql'), true, __('install.check_pdo_detail'));
        $add('mbstring', extension_loaded('mbstring'), true, '');
        $add('gd', extension_loaded('gd'), false, __('install.check_gd_detail'));
        $add('fileinfo', extension_loaded('fileinfo'), false, __('install.check_fileinfo_detail'));
        $add('sodium', extension_loaded('sodium'), false, __('install.check_sodium_detail'));

        foreach (self::WRITABLE_DIRECTORIES as $relative) {
            $path = MTL_ROOT . '/' . $relative;
            $add(
                __('install.check_writable', ['path' => $relative . '/']),
                is_dir($path) && is_writable($path),
                true,
                __('install.check_writable_detail')
            );
        }

        foreach (self::PROTECTED_DIRECTORIES as $relative) {
            if (is_dir(MTL_ROOT . '/' . $relative)) {
                $add(
                    __('install.check_htaccess', ['path' => $relative . '/']),
                    is_file(MTL_ROOT . '/' . $relative . '/.htaccess'),
                    false,
                    __('install.check_htaccess_detail')
                );
            }
        }

        // These two cannot be conjured up by the installer: their content is
        // project configuration, not boilerplate. Their absence is the classic
        // sign of an FTP client that hides dotfiles.
        $add(__('install.check_root_htaccess'), is_file(MTL_ROOT . '/.htaccess'), true, __('install.check_root_htaccess_detail'));
        $add('.user.ini', is_file(MTL_ROOT . '/.user.ini'), false, __('install.check_userini_detail'));

        $add(__('install.check_key'), (string) Config::get('app.key', '') !== '', true, __('install.check_key_detail'));

        return $checks;
    }

    /**
     * @param list<array{label:string,ok:bool,required:bool,detail:string}> $checks
     */
    public static function required(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    /**
     * @return array{ready:bool,version:string,error:string,pending:int,applied:int}
     */
    public static function databaseStatus(): array
    {
        $db = Database::instance();

        try {
            $db->scalar('SELECT 1');
        } catch (\Throwable $e) {
            // The wrapper says "unavailable"; the PDOException underneath says
            // *why* — wrong password, unknown host — which is what the person
            // staring at the installer needs.
            return [
                'ready'   => false,
                'version' => '',
                'error'   => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
                'pending' => 0,
                'applied' => 0,
            ];
        }

        $pending = 0;
        $applied = 0;

        foreach ((new Migrator($db))->status() as $entry) {
            $entry['applied'] ? $applied++ : $pending++;
        }

        return [
            'ready'   => true,
            'version' => $db->serverVersion(),
            'error'   => '',
            'pending' => $pending,
            'applied' => $applied,
        ];
    }

    /**
     * @return list<string> names of the migrations that ran
     */
    public static function migrate(): array
    {
        return (new Migrator(Database::instance()))->migrate();
    }
}
