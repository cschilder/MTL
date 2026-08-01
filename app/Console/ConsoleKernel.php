<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Core\Assets;
use MTL\Core\Autoloader;
use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Core\Logger;
use MTL\Core\Migrator;
use MTL\Core\Translator;
use MTL\Models\Step;
use MTL\Services\StepService;

defined('MTL_APP') || exit;

/**
 * Dispatches console commands.
 *
 * Commands are small closures rather than classes: there are a couple of dozen
 * of them, none is reused elsewhere, and keeping them in one file makes the
 * whole surface of the tool readable in one sitting.
 */
final class ConsoleKernel
{
    private Output $out;

    public function __construct()
    {
        $this->out = new Output();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $arguments = array_slice($argv, 1);

        // Flags are parsed out of the argument list so a command can read
        // --force or --limit=50 without each one re-implementing it.
        $options = [];
        $positional = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
                $options[$key] = $value;
                continue;
            }
            $positional[] = $argument;
        }

        try {
            $this->bootApplication($command);

            return $this->dispatch($command, $positional, $options);
        } catch (\Throwable $e) {
            $this->out->error($e->getMessage());

            if (isset($options['trace'])) {
                $this->out->line($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Loads configuration for every command except the ones that must work
     * before config/config.php exists.
     */
    private function bootApplication(string $command): void
    {
        $needsNoConfig = in_array($command, ['help', 'key:generate', 'install', 'version'], true);

        $file = MTL_ROOT . '/config/config.php';

        if (!is_file($file)) {
            if (!$needsNoConfig) {
                throw new \RuntimeException(
                    'config/config.php is missing. Copy config/config.example.php and fill in the database credentials.'
                );
            }

            Config::load(MTL_ROOT . '/config/config.example.php');
        } else {
            Config::load($file);
        }

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        Translator::setLocale((string) Config::get('app.locale', 'nl'));
        mb_internal_encoding('UTF-8');
    }

    /**
     * @param list<string>              $args
     * @param array<string,string|true> $options
     */
    private function dispatch(string $command, array $args, array $options): int
    {
        return match ($command) {
            'help', '--help', '-h' => $this->help(),
            'version'              => $this->version(),

            'migrate'        => $this->migrate(),
            'migrate:status' => $this->migrateStatus(),
            'migrate:fresh'  => $this->migrateFresh($options),

            'key:generate'   => $this->keyGenerate(),

            'install'        => (new InstallCommand($this->out))->run($options),

            'user:create'    => (new UserCommand($this->out))->create($args, $options),
            'user:list'      => (new UserCommand($this->out))->list(),
            'user:password'  => (new UserCommand($this->out))->password($args),
            'user:role'      => (new UserCommand($this->out))->role($args),

            'seed:demo'      => (new SeedCommand($this->out))->demo($options),

            'media:rebuild'  => (new MediaCommand($this->out))->rebuild($options),
            'media:verify'   => (new MediaCommand($this->out))->verify(),
            'media:prune'    => (new MediaCommand($this->out))->prune($options),

            'search:reindex' => (new SearchCommand($this->out))->reindex(),

            'content:rerender' => (new ContentCommand($this->out))->rerender($options),

            'geocode:backfill' => $this->geocodeBackfill(),

            'maintenance'    => (new MaintenanceCommand($this->out))->run($options),

            'optimize'       => $this->optimize(),
            'cache:clear'    => $this->cacheClear(),

            'globe:build'    => (new GlobeCommand($this->out))->build($options),

            'test'           => (new TestCommand($this->out))->run($options),

            'serve'          => $this->serve($options),

            default          => $this->unknown($command),
        };
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    private function help(): int
    {
        $this->out->title('MTL console');
        $this->out->line('');
        $this->out->line('Usage: php bin/console.php <command> [options]');
        $this->out->line('');

        $groups = [
            'Setup' => [
                'install'          => 'Interactive first-time setup: config, schema, admin account',
                'key:generate'     => 'Print a new APP_KEY',
                'migrate'          => 'Apply pending database migrations',
                'migrate:status'   => 'Show which migrations have run',
                'migrate:fresh'    => 'Drop every table and migrate from scratch (--force)',
            ],
            'Users' => [
                'user:create'      => 'Create an account: user:create <email> <name> [--role=admin]',
                'user:list'        => 'List accounts',
                'user:password'    => 'Set a password: user:password <email>',
                'user:role'        => 'Change a role: user:role <email> <role>',
            ],
            'Content' => [
                'seed:demo'        => 'Insert a demo trip with steps and placeholder media',
                'search:reindex'   => 'Rebuild the full-text search index',
                'media:rebuild'    => 'Regenerate image variants (--missing to skip existing)',
                'media:verify'     => 'Report media rows whose file is missing, and vice versa',
                'media:prune'      => 'Delete soft-deleted media older than --days=30',
                'content:rerender' => 'Re-render stored HTML from the markdown (--dry-run)',
                'geocode:backfill' => 'Fill in coordinates for stops that have none (photos, then place names)',
            ],
            'Operations' => [
                'maintenance'      => 'Run the scheduled maintenance tasks',
                'optimize'         => 'Build the classmap and asset manifest',
                'cache:clear'      => 'Remove generated caches',
                'globe:build'      => 'Regenerate the globe land geometry from source data',
                'test'             => 'Run the test suite (--filter=name)',
                'serve'            => 'Start PHP\'s development server (--port=8000)',
            ],
        ];

        foreach ($groups as $group => $commands) {
            $this->out->line($this->out->bold($group));
            foreach ($commands as $name => $description) {
                $this->out->line(sprintf('  %-18s %s', $name, $description));
            }
            $this->out->line('');
        }

        return 0;
    }

    private function version(): int
    {
        $this->out->line('MTL ' . $this->readVersion());
        $this->out->line('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');

        if (Config::loaded()) {
            $this->out->line('Database ' . Database::instance()->serverVersion());
        }

        return 0;
    }

    private function readVersion(): string
    {
        $file = MTL_ROOT . '/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : 'dev';
    }

    private function migrate(): int
    {
        $migrator = new Migrator(Database::instance());

        $ran = $migrator->migrate(function (string $name): void {
            $this->out->success('applied ' . $name);
        });

        if ($ran === []) {
            $this->out->info('Nothing to migrate; the schema is up to date.');
        } else {
            $this->out->success(count($ran) . ' migration(s) applied.');
        }

        return 0;
    }

    private function migrateStatus(): int
    {
        $rows = [];

        foreach ((new Migrator(Database::instance()))->status() as $entry) {
            $state = $entry['applied'] ? 'applied' : 'pending';

            if ($entry['applied'] && !$entry['checksum_matches']) {
                $state = 'applied (file changed since!)';
            }

            $rows[] = [$entry['version'], $state];
        }

        $this->out->table(['Migration', 'Status'], $rows);

        return 0;
    }

    /**
     * @param array<string,string|true> $options
     */
    private function migrateFresh(array $options): int
    {
        if (!isset($options['force'])) {
            $this->out->error('migrate:fresh drops every table. Re-run with --force if that is what you want.');

            return 1;
        }

        $db = Database::instance();
        $database = (string) Config::get('database.name');

        $tables = $db->select(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?',
            [$database]
        );

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $row) {
            $db->statement('DROP TABLE IF EXISTS ' . Database::quoteIdentifier((string) $row['t']));
        }

        $db->statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->out->info('Dropped ' . count($tables) . ' table(s).');

        return $this->migrate();
    }

    private function keyGenerate(): int
    {
        $key = base64_encode(random_bytes(32));

        $this->out->line('');
        $this->out->line('Put this in config/config.php under app.key:');
        $this->out->line('');
        $this->out->line('    ' . $this->out->bold($key));
        $this->out->line('');
        $this->out->warn('Changing an existing key signs everyone out and makes stored 2FA secrets unreadable.');

        return 0;
    }

    private function optimize(): int
    {
        $classes = Autoloader::buildClassmap();
        $this->out->success('Class map: ' . $classes . ' classes.');

        $assets = Assets::buildManifest();
        $this->out->success('Asset manifest: ' . $assets . ' files.');

        return 0;
    }

    /**
     * Places every stop in the database that has no coordinates yet, from its
     * photos' geotags, its location name, or its title — the same routine as
     * the button on the trip edit screen, for whole libraries at once.
     */
    private function geocodeBackfill(): int
    {
        $rows = Step::active()
            ->whereNull('latitude')
            ->orderBy('trip_id')
            ->orderBy('position')
            ->get();

        if ($rows === []) {
            $this->out->info('Every stop already has coordinates.');

            return 0;
        }

        $placed = 0;

        foreach (Step::fromRows($rows) as $step) {
            $label = $step->string('title');

            if (StepService::place($step)) {
                $this->out->success(sprintf('%s → %.5f, %.5f', $label, $step->latitude(), $step->longitude()));
                $placed++;
            } else {
                $this->out->warn($label . ': no photo geotag and no place found for its name');
            }
        }

        $this->out->line('');
        $this->out->info($placed . ' of ' . count($rows) . ' stop(s) placed.');

        return 0;
    }

    private function cacheClear(): int
    {
        $removed = 0;

        foreach (glob(MTL_ROOT . '/storage/cache/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep' && @unlink($file)) {
                ++$removed;
            }
        }

        $this->out->success('Removed ' . $removed . ' cached file(s).');

        return 0;
    }

    /**
     * @param array<string,string|true> $options
     */
    private function serve(array $options): int
    {
        $port = (int) ($options['port'] ?? 8000);
        $host = (string) ($options['host'] ?? '127.0.0.1');

        $this->out->info('MTL is listening on http://' . $host . ':' . $port);
        $this->out->line('Press Ctrl+C to stop.');

        // The built-in server has no rewrite engine, so index.php acts as the
        // router script and serves real files itself.
        $command = sprintf(
            '%s -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            $port,
            escapeshellarg(MTL_ROOT),
            escapeshellarg(MTL_ROOT . '/router.php')
        );

        passthru($command, $status);

        return $status;
    }

    private function unknown(string $command): int
    {
        $this->out->error('Unknown command: ' . $command);
        $this->out->line('Run `php bin/console.php help` for the list.');

        return 1;
    }
}
