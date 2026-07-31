<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Applies the SQL files in db/migrations in filename order and records what
 * has run in a `migrations` table.
 *
 * Plain .sql files rather than PHP classes: the schema is readable on its own,
 * and it can be handed to phpMyAdmin unchanged when a host makes running the
 * console awkward. The token {{prefix}} is replaced with the configured table
 * prefix so several installations can share one Strato database.
 */
final class Migrator
{
    private string $directory;

    public function __construct(
        private readonly Database $db,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? MTL_ROOT . '/db/migrations';
    }

    /**
     * Runs every migration that has not been applied yet.
     *
     * @return list<string> names of the migrations that ran
     */
    public function migrate(?callable $onProgress = null): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedVersions();
        $ran = [];

        $batch = ((int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM ' . $this->table('migrations'))) + 1;

        foreach ($this->pending($applied) as $file) {
            $name = basename($file, '.sql');
            $started = microtime(true);

            $this->applyFile($file);

            $this->db->table('migrations')->insert([
                'version'     => $name,
                'batch'       => $batch,
                'checksum'    => hash_file('sha256', $file),
                'runtime_ms'  => (int) round((microtime(true) - $started) * 1000),
                'applied_at'  => gmdate('Y-m-d H:i:s'),
            ]);

            $ran[] = $name;

            if ($onProgress !== null) {
                $onProgress($name);
            }
        }

        return $ran;
    }

    /**
     * Migrations present on disk but not yet recorded as applied.
     *
     * @param list<string> $applied
     *
     * @return list<string> absolute file paths
     */
    private function pending(array $applied): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file, '.sql'), $applied, true)
        ));
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $this->ensureMigrationsTable();

        /** @var list<string> $versions */
        $versions = $this->db->table('migrations')->orderBy('version')->pluck('version');

        return array_map('strval', $versions);
    }

    /**
     * @return list<array{version:string,applied:bool,checksum_matches:bool}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $records = [];
        foreach ($this->db->table('migrations')->get() as $row) {
            $records[(string) $row['version']] = (string) $row['checksum'];
        }

        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        $status = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $applied = array_key_exists($version, $records);

            $status[] = [
                'version'          => $version,
                'applied'          => $applied,
                // A changed checksum means a migration was edited after it ran,
                // which makes two installations diverge silently.
                'checksum_matches' => !$applied || $records[$version] === hash_file('sha256', $file),
            ];
        }

        return $status;
    }

    /**
     * Executes one .sql file.
     *
     * MySQL cannot run several statements through a prepared statement, so the
     * file is split on semicolons that sit outside quotes and comments.
     */
    private function applyFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $sql = str_replace('{{prefix}}', $this->db->prefix(), $sql);

        $statements = self::splitStatements($sql);

        // DDL in MySQL commits implicitly, so a failed migration cannot be
        // rolled back as a unit. Failing loudly on the first bad statement at
        // least leaves the operator with a precise error.
        foreach ($statements as $statement) {
            try {
                $this->db->pdo()->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    'Migration ' . basename($file) . ' failed: ' . $e->getMessage()
                        . "\nStatement: " . substr($statement, 0, 400),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Splits a SQL script into individual statements.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    ++$i;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    ++$i;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    ++$i;
                    continue;
                }
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                // A backslash-escaped quote stays inside the string.
                if (!self::isEscaped($sql, $i)) {
                    $inSingle = !$inSingle;
                }
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                if (!self::isEscaped($sql, $i)) {
                    $inDouble = !$inDouble;
                }
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private static function isEscaped(string $sql, int $position): bool
    {
        $backslashes = 0;
        for ($i = $position - 1; $i >= 0 && $sql[$i] === '\\'; --$i) {
            ++$backslashes;
        }

        return $backslashes % 2 === 1;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->table('migrations') . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                version VARCHAR(191) NOT NULL,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                checksum CHAR(64) NOT NULL DEFAULT \'\',
                runtime_ms INT UNSIGNED NOT NULL DEFAULT 0,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function table(string $name): string
    {
        return Database::quoteIdentifier($this->db->prefix() . $name);
    }

    /** True when the schema is present and up to date. */
    public function isUpToDate(): bool
    {
        foreach ($this->status() as $entry) {
            if (!$entry['applied']) {
                return false;
            }
        }

        return true;
    }
}
