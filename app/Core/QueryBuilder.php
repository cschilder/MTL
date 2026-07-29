<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * A small fluent SQL builder.
 *
 * It covers the subset the application actually needs — filters, joins,
 * ordering, pagination and simple aggregates. Anything more involved is
 * written as SQL and passed to Database::select() directly, which keeps this
 * class from growing into a half-finished ORM.
 *
 * Identifiers are validated against a whitelist pattern and values always
 * travel as bound parameters.
 */
final class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{type:string,sql:string,boolean:string}> */
    private array $wheres = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $havings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

    /** @var array<int,mixed> */
    private array $bindings = [];

    private string $alias = '';

    public function __construct(
        private readonly Database $db,
        private readonly string $table,
    ) {
    }

    // -------------------------------------------------------------------------
    // Shaping the SELECT
    // -------------------------------------------------------------------------

    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_map([$this, 'column'], $columns);

        return $this;
    }

    public function addSelect(string ...$columns): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        foreach ($columns as $column) {
            $this->columns[] = $this->column($column);
        }

        return $this;
    }

    public function selectRaw(string $sql): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns[] = $sql;

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function as(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Joins
    // -------------------------------------------------------------------------

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new \InvalidArgumentException('Unsupported join type: ' . $type);
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->tableRef($table),
            $this->column($first),
            $this->operator($operator),
            $this->column($second),
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function where(string $column, string $operator, mixed $value = null, string $boolean = 'AND'): self
    {
        // where('id', 5) shorthand.
        if ($value === null && !in_array(strtoupper($operator), ['IS NULL', 'IS NOT NULL'], true)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->column($column) . ' ' . $this->operator($operator) . ' ?',
            'boolean' => $boolean,
        ];
        $this->bindings[] = $value;

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if ($values === []) {
            // An empty IN () is a syntax error in MySQL; a contradiction gives
            // the semantically correct empty result instead.
            $this->wheres[] = [
                'type'    => 'raw',
                'sql'     => $not ? '1 = 1' : '1 = 0',
                'boolean' => $boolean,
            ];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'type'    => 'in',
            'sql'     => $this->column($column) . ($not ? ' NOT IN (' : ' IN (') . $placeholders . ')',
            'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): self
    {
        $this->wheres[] = [
            'type'    => 'null',
            'sql'     => $this->column($column) . ($not ? ' IS NOT NULL' : ' IS NULL'),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type'    => 'between',
            'sql'     => $this->column($column) . ' BETWEEN ? AND ?',
            'boolean' => $boolean,
        ];
        $this->bindings[] = $from;
        $this->bindings[] = $to;

        return $this;
    }

    /**
     * A LIKE filter with the wildcards supplied by the builder, so a search
     * term containing % or _ matches those characters literally.
     */
    public function whereLike(string $column, string $value, string $boolean = 'AND'): self
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        $this->wheres[] = [
            'type'    => 'like',
            'sql'     => $this->column($column) . " LIKE ? ESCAPE '\\\\'",
            'boolean' => $boolean,
        ];
        $this->bindings[] = '%' . $escaped . '%';

        return $this;
    }

    /**
     * Groups conditions in parentheses:
     *   ->whereGroup(fn($q) => $q->where('a', 1)->orWhere('b', 2))
     */
    public function whereGroup(callable $callback, string $boolean = 'AND'): self
    {
        $nested = new self($this->db, $this->table);
        $callback($nested);

        if ($nested->wheres === []) {
            return $this;
        }

        $this->wheres[] = [
            'type'    => 'nested',
            'sql'     => '(' . $nested->compileWheres(false) . ')',
            'boolean' => $boolean,
        ];

        foreach ($nested->bindings as $binding) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /** Applies a filter only when $condition holds, for optional search inputs. */
    public function when(mixed $condition, callable $callback): self
    {
        if ($condition) {
            $callback($this, $condition);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Grouping and ordering
    // -------------------------------------------------------------------------

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->column($column);
        }

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function having(string $sql, array $bindings = []): self
    {
        $this->havings[] = $sql;

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = $this->column($column) . ' ' . $direction;

        return $this;
    }

    public function orderByRaw(string $sql): self
    {
        $this->orders[] = $sql;

        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Executing
    // -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function get(): array
    {
        return $this->db->select($this->toSql(), $this->bindings);
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $previous = $this->limit;
        $this->limit = 1;

        $row = $this->db->selectOne($this->toSql(), $this->bindings);

        $this->limit = $previous;

        return $row;
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        if ($row === null) {
            return null;
        }

        return reset($row) ?: null;
    }

    /**
     * A flat list of one column, e.g. ->pluck('id').
     *
     * @return list<mixed>
     */
    public function pluck(string $column, ?string $keyBy = null): array
    {
        $builder = clone $this;
        $builder->columns = $keyBy === null
            ? [$this->column($column)]
            : [$this->column($keyBy), $this->column($column)];

        $rows = $builder->get();
        $result = [];

        foreach ($rows as $row) {
            $values = array_values($row);
            if ($keyBy === null) {
                $result[] = $values[0];
            } else {
                $result[(string) $values[0]] = $values[1];
            }
        }

        return $result;
    }

    public function count(string $column = '*'): int
    {
        $builder = clone $this;
        $builder->orders = [];
        $builder->limit = null;
        $builder->offset = null;
        $builder->columns = ['COUNT(' . ($column === '*' ? '*' : $this->column($column)) . ') AS aggregate'];

        // A grouped query counts groups, not rows, so wrap it.
        if ($builder->groups !== []) {
            $sql = 'SELECT COUNT(*) AS aggregate FROM (' . $builder->toSql() . ') AS grouped_count';

            return (int) $this->db->scalar($sql, $builder->bindings);
        }

        return (int) $this->db->scalar($builder->toSql(), $builder->bindings);
    }

    public function exists(): bool
    {
        $builder = clone $this;
        $builder->columns = ['1'];
        $builder->limit = 1;
        $builder->orders = [];

        return $this->db->scalar($builder->toSql(), $builder->bindings) !== null;
    }

    public function sum(string $column): float
    {
        $builder = clone $this;
        $builder->columns = ['COALESCE(SUM(' . $this->column($column) . '), 0) AS aggregate'];
        $builder->orders = [];

        return (float) $this->db->scalar($builder->toSql(), $builder->bindings);
    }

    public function max(string $column): mixed
    {
        $builder = clone $this;
        $builder->columns = ['MAX(' . $this->column($column) . ') AS aggregate'];
        $builder->orders = [];

        return $this->db->scalar($builder->toSql(), $builder->bindings);
    }

    /**
     * One page of results plus the metadata a pager needs.
     *
     * @return array{data:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(int $page, int $perPage = 24): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = $this->count();

        $this->limit($perPage)->offset(($page - 1) * $perPage);

        return [
            'data'     => $total === 0 ? [] : $this->get(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Streams large result sets in fixed-size pages so a maintenance task can
     * walk the whole media table without loading it into memory.
     *
     * @param callable(list<array<string,mixed>>):void $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $page = 1;

        do {
            $builder = clone $this;
            $rows = $builder->limit($size)->offset(($page - 1) * $size)->get();

            if ($rows === []) {
                return;
            }

            $callback($rows);
            ++$page;
        } while (count($rows) === $size);
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $values
     */
    public function insert(array $values): int
    {
        [$sql, $bindings] = $this->compileInsert($values);

        return $this->db->insert($sql, $bindings);
    }

    /**
     * Inserts, or updates the listed columns when the row already exists.
     *
     * @param array<string,mixed> $values
     * @param list<string>        $update columns to overwrite on conflict
     */
    public function upsert(array $values, array $update): int
    {
        [$sql, $bindings] = $this->compileInsert($values);

        $assignments = [];
        foreach ($update as $column) {
            $quoted = Database::quoteIdentifier($column);
            $assignments[] = $quoted . ' = VALUES(' . $quoted . ')';
        }

        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $this->db->run($sql, $bindings);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $quoted = array_map([Database::class, 'quoteIdentifier'], $columns);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        $sql = 'INSERT INTO ' . $this->tableRef($this->table)
            . ' (' . implode(', ', $quoted) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $placeholders));

        return $this->db->statement($sql, $bindings);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                $assignments[] = Database::quoteIdentifier($column) . ' = ' . $value;
                continue;
            }

            $assignments[] = Database::quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = 'UPDATE ' . $this->tableRef($this->table) . ' SET ' . implode(', ', $assignments);

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres(false);
        }

        return $this->db->statement($sql, array_merge($bindings, $this->bindings));
    }

    public function increment(string $column, int $amount = 1): int
    {
        $quoted = Database::quoteIdentifier($column);

        return $this->update([$column => $this->db->raw($quoted . ' + ' . $amount)]);
    }

    public function delete(): int
    {
        $sql = 'DELETE FROM ' . $this->tableRef($this->table);

        if ($this->wheres === []) {
            // Guard against a forgotten where() wiping a table. Callers that
            // really mean it use truncate().
            throw new \LogicException('Refusing to delete every row; add a where() or call truncate().');
        }

        $sql .= ' WHERE ' . $this->compileWheres(false);

        return $this->db->statement($sql, $this->bindings);
    }

    public function truncate(): void
    {
        $this->db->statement('TRUNCATE TABLE ' . $this->tableRef($this->table));
    }

    // -------------------------------------------------------------------------
    // Compilation
    // -------------------------------------------------------------------------

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $this->columns)
            . ' FROM ' . $this->tableRef($this->table) . ($this->alias !== '' ? ' AS ' . Database::quoteIdentifier($this->alias) : '');

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres(false);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            // MySQL rejects OFFSET without LIMIT.
            if ($this->limit === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /** @return array<int,mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function compileWheres(bool $withKeyword = true): string
    {
        $parts = [];

        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : $where['boolean'] . ' ';
            $parts[] = $prefix . $where['sql'];
        }

        return ($withKeyword ? 'WHERE ' : '') . implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function compileInsert(array $values): array
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $columns[] = Database::quoteIdentifier($column);

            if ($value instanceof Expression) {
                $placeholders[] = (string) $value;
                continue;
            }

            $placeholders[] = '?';
            $bindings[] = $value;
        }

        $sql = 'INSERT INTO ' . $this->tableRef($this->table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return [$sql, $bindings];
    }

    /** Qualifies and quotes `table` or `table.column`. */
    private function column(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        // Allow an explicit alias: "u.name AS author".
        if (preg_match('/^(?<expr>[A-Za-z_][A-Za-z0-9_.]*)\s+AS\s+(?<alias>[A-Za-z_][A-Za-z0-9_]*)$/i', $column, $m) === 1) {
            return $this->column($m['expr']) . ' AS ' . Database::quoteIdentifier($m['alias']);
        }

        if (str_contains($column, '.')) {
            [$table, $field] = explode('.', $column, 2);

            $quotedTable = $table === $this->alias
                ? Database::quoteIdentifier($table)
                : $this->tableRef($table);

            return $quotedTable . '.' . ($field === '*' ? '*' : Database::quoteIdentifier($field));
        }

        return Database::quoteIdentifier($column);
    }

    /** Applies the configured prefix and quotes a table name. */
    private function tableRef(string $table): string
    {
        return Database::quoteIdentifier($this->db->prefix() . $table);
    }

    private function operator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        $allowed = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT', '<=>'];

        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported operator: ' . $operator);
        }

        return $operator;
    }
}
