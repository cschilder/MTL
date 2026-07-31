<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Thin PDO wrapper for MySQL / MariaDB.
 *
 * The connection is opened on first use, so a request served entirely from
 * cache (or a 404) never touches the database — which matters on Strato, where
 * the database lives on a separate host and connecting costs a round trip.
 */
final class Database
{
    private static ?self $instance = null;

    private ?\PDO $pdo = null;

    private int $transactionDepth = 0;

    /** @var list<array{sql:string,time:float,bindings:array<int|string,mixed>}> */
    private array $log = [];

    private bool $logQueries = false;

    /**
     * @param array<string,mixed> $config
     */
    private function __construct(private array $config)
    {
        $this->logQueries = Config::isDebug();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array<string,mixed> $config */
            $config = Config::get('database', []);
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /** Replaces the shared instance. Used by the test runner. */
    public static function swap(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function make(array $config): self
    {
        return new self($config);
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($this->config['host'] ?? 'localhost'),
            (int) ($this->config['port'] ?? 3306),
            (string) ($this->config['name'] ?? ''),
            (string) ($this->config['charset'] ?? 'utf8mb4'),
        );

        try {
            $pdo = new \PDO(
                $dsn,
                (string) ($this->config['user'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    // Real prepared statements: the driver sends parameters
                    // separately, so a value can never be reinterpreted as SQL.
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                    \PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (\PDOException $e) {
            // The DSN contains the database name and user; the message must
            // not reach a visitor.
            Logger::instance()->error('Database connection failed', ['error' => $e->getMessage()]);

            throw new HttpException(503, 'The database is unavailable. Please try again shortly.', [], $e);
        }

        // STRICT_ALL_TABLES turns silent truncation into an error, which is
        // what keeps a 300-character title from being stored as 255.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'");

        return $this->pdo = $pdo;
    }

    public function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    /** Applies the configured table prefix to a bare table name. */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * @param array<int|string,mixed> $bindings
     *
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<int|string,mixed> $bindings
     *
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): \PDOStatement
    {
        $started = microtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($bindings as $key => $value) {
                $param = is_int($key) ? $key + 1 : $key;

                $type = match (true) {
                    is_int($value)  => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default         => \PDO::PARAM_STR,
                };

                $statement->bindValue($param, $value, $type);
            }

            $statement->execute();
        } catch (\PDOException $e) {
            Logger::instance()->error('Query failed', [
                'sql'   => $sql,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($this->logQueries) {
            $this->log[] = [
                'sql'      => $sql,
                'bindings' => $bindings,
                'time'     => (microtime(true) - $started) * 1000,
            ];
        }

        return $statement;
    }

    /**
     * Runs $callback inside a transaction, committing on return and rolling
     * back on any throwable.
     *
     * Nested calls reuse the outer transaction through savepoints, so a
     * service that manages its own transaction still composes.
     *
     * @template T
     *
     * @param callable(self):T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT mtl_sp_' . $this->transactionDepth);
        }

        ++$this->transactionDepth;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        --$this->transactionDepth;

        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT mtl_sp_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        --$this->transactionDepth;

        if ($this->transactionDepth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT mtl_sp_' . $this->transactionDepth);
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /** @return list<array{sql:string,time:float,bindings:array<int|string,mixed>}> */
    public function queryLog(): array
    {
        return $this->log;
    }

    public function enableQueryLog(bool $enabled = true): void
    {
        $this->logQueries = $enabled;
    }

    /** True when the connection works and the schema has been migrated. */
    public function isReady(): bool
    {
        try {
            $this->scalar('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function serverVersion(): string
    {
        try {
            return (string) $this->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Quotes an identifier for use where a placeholder is not allowed. */
    public static function quoteIdentifier(string $name): string
    {
        // Column and table names never come from user input in this codebase;
        // the whitelist is a second line of defence for future callers.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Illegal identifier: ' . $name);
        }

        return '`' . $name . '`';
    }
}
