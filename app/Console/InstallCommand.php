<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Auth\Password;
use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Core\Migrator;
use MTL\Models\User;
use MTL\Services\SettingsService;

defined('MTL_APP') || exit;

/**
 * First-time setup: write the configuration, create the schema, create the
 * first administrator.
 */
final class InstallCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param array<string,string|true> $options
     */
    public function run(array $options): int
    {
        $this->out->title('MTL setup');
        $this->out->line('');

        if (!$this->checkEnvironment()) {
            return 1;
        }

        $configFile = MTL_ROOT . '/config/config.php';

        if (is_file($configFile) && !isset($options['force'])) {
            $this->out->info('config/config.php already exists; keeping it.');
        } elseif (!$this->writeConfiguration($configFile, $options)) {
            return 1;
        }

        // Reload with whatever was just written.
        Config::load($configFile);
        Database::swap(null);

        $this->out->line('');
        $this->out->info('Connecting to the database…');

        if (!Database::instance()->isReady()) {
            $this->out->error('Could not connect. Check the credentials in config/config.php.');

            return 1;
        }

        $this->out->success('Connected to ' . Database::instance()->serverVersion() . '.');

        $ran = (new Migrator(Database::instance()))->migrate(function (string $name): void {
            $this->out->success('applied ' . $name);
        });

        if ($ran === []) {
            $this->out->info('The schema was already up to date.');
        }

        if (User::noneExist()) {
            $this->out->line('');
            $this->out->info('Creating the first administrator.');

            if ((new UserCommand($this->out))->create([], ['role' => 'admin'] + $options) !== 0) {
                return 1;
            }
        }

        $this->seedSettings();

        $this->out->line('');
        $this->out->success('MTL is ready. Sign in at ' . Config::get('app.url') . '/login');

        return 0;
    }

    private function checkEnvironment(): bool
    {
        $checks = [
            ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION],
            ['pdo_mysql', extension_loaded('pdo_mysql'), ''],
            ['mbstring', extension_loaded('mbstring'), ''],
            ['gd (image resizing)', extension_loaded('gd'), 'thumbnails need this'],
            ['fileinfo (type detection)', extension_loaded('fileinfo'), ''],
            ['storage/ writable', is_writable(MTL_ROOT . '/storage'), 'chmod 0775 storage'],
            ['config/ writable', is_writable(MTL_ROOT . '/config'), 'needed to write config.php'],
        ];

        $ok = true;

        foreach ($checks as [$label, $passed, $note]) {
            if ($passed) {
                $this->out->success($label);
                continue;
            }

            // gd and fileinfo degrade rather than block: without them uploads
            // still work, they just keep their original size and rely on the
            // extension for the type.
            $optional = str_starts_with($label, 'gd') || str_starts_with($label, 'fileinfo');

            if ($optional) {
                $this->out->warn($label . ' is missing — ' . $note);
                continue;
            }

            $this->out->error($label . ' — ' . $note);
            $ok = false;
        }

        return $ok;
    }

    /**
     * @param array<string,string|true> $options
     */
    private function writeConfiguration(string $file, array $options): bool
    {
        $this->out->line('');
        $this->out->info('Database connection');

        $host = (string) ($options['db-host'] ?? $this->out->ask('Host', 'rdbms.strato.de'));
        $name = (string) ($options['db-name'] ?? $this->out->ask('Database'));
        $user = (string) ($options['db-user'] ?? $this->out->ask('User'));
        $password = (string) ($options['db-password'] ?? $this->out->askHidden('Password'));

        $url = (string) ($options['url'] ?? $this->out->ask('Site URL', 'https://mtl.r010.space'));

        $key = base64_encode(random_bytes(32));

        $template = file_get_contents(MTL_ROOT . '/config/config.example.php');

        if ($template === false) {
            $this->out->error('config/config.example.php is missing.');

            return false;
        }

        // Rather than templating the example file, write a small config that
        // sets the values that differ and inherits the rest. That way a later
        // MTL version adding a setting does not require editing this file.
        $contents = "<?php\n\n"
            . "/**\n"
            . " * MTL configuration, written by `php bin/console.php install`.\n"
            . " *\n"
            . " * Values not set here fall back to config.example.php, so an upgrade that\n"
            . " * introduces a new setting does not need this file to change.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "return array_replace_recursive(require __DIR__ . '/config.example.php', [\n"
            . "    'app' => [\n"
            . "        'url'   => " . var_export(rtrim($url, '/'), true) . ",\n"
            . "        'env'   => 'production',\n"
            . "        'debug' => false,\n"
            . "        'key'   => " . var_export($key, true) . ",\n"
            . "    ],\n"
            . "    'database' => [\n"
            . "        'host'     => " . var_export($host, true) . ",\n"
            . "        'name'     => " . var_export($name, true) . ",\n"
            . "        'user'     => " . var_export($user, true) . ",\n"
            . "        'password' => " . var_export($password, true) . ",\n"
            . "    ],\n"
            . "]);\n";

        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            $this->out->error('Could not write ' . $file . '.');

            return false;
        }

        // The file holds database credentials and the application key.
        @chmod($file, 0640);

        $this->out->success('Wrote config/config.php.');

        return true;
    }

    private function seedSettings(): void
    {
        if (SettingsService::string('site.title') !== '' && SettingsService::string('site.title') !== 'MTL') {
            return;
        }

        $url = (string) Config::get('app.url', '');
        $host = parse_url($url, PHP_URL_HOST) ?: 'MTL';

        SettingsService::set('site.title', $host === 'mtl.r010.space' ? 'MTL' : (string) $host);
    }
}
