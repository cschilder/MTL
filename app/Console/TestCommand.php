<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Core\Autoloader;
use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Core\Migrator;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Discovers and runs the test suite.
 *
 *   php bin/console.php test
 *   php bin/console.php test --filter=Markdown
 *   php bin/console.php test --integration     (needs a database)
 */
final class TestCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param array<string,string|true> $options
     */
    public function run(array $options): int
    {
        Autoloader::register(['MTL\\Tests\\' => MTL_ROOT . '/tests']);

        $filter = is_string($options['filter'] ?? null) ? $options['filter'] : null;
        $wantsIntegration = isset($options['integration']) || isset($options['all']);

        if ($wantsIntegration) {
            $this->prepareTestDatabase();
        }

        $classes = $this->discover();

        if ($classes === []) {
            $this->out->warn('No test classes found under tests/.');

            return 1;
        }

        $this->out->title('MTL test suite');
        $this->out->line('');

        $passed = 0;
        $failed = 0;
        $skipped = 0;
        /** @var list<array{test:string,message:string,detail:string}> $failures */
        $failures = [];
        $started = microtime(true);

        foreach ($classes as $class) {
            /** @var class-string<TestCase> $class */
            $needsDatabase = str_contains($class, '\\Integration\\');

            if ($needsDatabase && !$wantsIntegration) {
                ++$skipped;
                continue;
            }

            $class::setUpClass();

            $instance = new $class();
            $results = $instance->run($filter);

            foreach ($results as $result) {
                if ($result['passed']) {
                    ++$passed;
                    $this->out->line(
                        '  ' . $this->out->dim('✓') . ' ' . $this->shorten($result['test'])
                        . $this->out->dim('  ' . number_format($result['time'], 1) . 'ms')
                    );
                    continue;
                }

                ++$failed;
                $failures[] = $result;
                $this->out->line('  ✗ ' . $this->shorten($result['test']));
            }

            $class::tearDownClass();
        }

        $elapsed = (microtime(true) - $started) * 1000;

        $this->out->line('');

        foreach ($failures as $failure) {
            $this->out->error($this->shorten($failure['test']));
            $this->out->line('    ' . $failure['message']);

            if ($failure['detail'] !== '') {
                foreach (explode("\n", $failure['detail']) as $line) {
                    $this->out->line('    ' . $this->out->dim($line));
                }
            }

            $this->out->line('');
        }

        $summary = sprintf(
            '%d passed, %d failed in %s ms',
            $passed,
            $failed,
            number_format($elapsed, 0)
        );

        if ($failed === 0) {
            $this->out->success($summary);
        } else {
            $this->out->error($summary);
        }

        if ($skipped > 0) {
            $this->out->info($skipped . ' integration class(es) skipped; re-run with --integration to include them.');
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * @return list<class-string<TestCase>>
     */
    private function discover(): array
    {
        $root = MTL_ROOT . '/tests';

        if (!is_dir($root)) {
            return [];
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php' || !str_ends_with($file->getBasename('.php'), 'Test')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $class = 'MTL\\Tests\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(TestCase::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Points the application at the test database and migrates it from scratch.
     *
     * A separate database rather than transactions-and-rollback: the schema has
     * foreign keys and the migrations use DDL, which MySQL commits implicitly.
     */
    private function prepareTestDatabase(): void
    {
        $name = (string) (env('DB_TEST_NAME') ?? (Config::get('database.name') . '_test'));

        Config::set(['database' => ['name' => $name]]);
        Database::swap(null);

        $db = Database::instance();

        if (!$db->isReady()) {
            throw new \RuntimeException(
                'Cannot reach the test database "' . $name . '". Create it, or set DB_TEST_NAME.'
            );
        }

        $tables = $db->select(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?',
            [$name]
        );

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $row) {
            $db->statement('DROP TABLE IF EXISTS ' . Database::quoteIdentifier((string) $row['t']));
        }

        $db->statement('SET FOREIGN_KEY_CHECKS = 1');

        (new Migrator($db))->migrate();

        $this->out->info('Test database "' . $name . '" rebuilt.');
    }

    private function shorten(string $test): string
    {
        return str_replace('MTL\\Tests\\', '', $test);
    }
}
