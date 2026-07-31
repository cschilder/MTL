<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Core\Database;
use MTL\Core\QueryBuilder;

defined('MTL_APP') || exit;

/**
 * A thin record object over a database row.
 *
 * Deliberately not an ORM: there is no relation mapper, no change tracking and
 * no lazy loading. Models give typed access to a row and hold the small pieces
 * of logic that belong to the record itself; everything that spans records
 * lives in a service class where the queries are visible.
 *
 * @implements \ArrayAccess<string,mixed>
 */
abstract class Model implements \ArrayAccess, \JsonSerializable
{
    /** Table name without the configured prefix. */
    protected static string $table = '';

    /**
     * Columns that hold JSON. They are decoded on read and encoded on write.
     *
     * @var list<string>
     */
    protected static array $jsonColumns = [];

    /**
     * Columns holding a UTC datetime, exposed as DateTimeImmutable.
     *
     * @var list<string>
     */
    protected static array $dateColumns = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Columns never included in toArray()/jsonSerialize() output.
     *
     * @var list<string>
     */
    protected static array $hidden = [];

    /** @var array<string,mixed> */
    protected array $attributes = [];

    /** @var array<string,mixed> decoded values cached per instance */
    private array $casts = [];

    /**
     * @param array<string,mixed> $attributes
     */
    final public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<static>
     */
    public static function fromRows(array $rows): array
    {
        return array_map(static fn (array $row): static => new static($row), $rows);
    }

    public static function table(): string
    {
        if (static::$table === '') {
            throw new \LogicException(static::class . ' must define $table.');
        }

        return static::$table;
    }

    public static function query(): QueryBuilder
    {
        return Database::instance()->table(static::table());
    }

    /** Excludes soft-deleted rows, which is what almost every read wants. */
    public static function active(): QueryBuilder
    {
        return static::query()->whereNull('deleted_at');
    }

    public static function find(int|string $id): ?static
    {
        $row = static::query()->where('id', '=', $id)->first();

        return $row === null ? null : new static($row);
    }

    public static function findByUuid(string $uuid): ?static
    {
        if (!self::isUuid($uuid)) {
            return null;
        }

        $row = static::query()->where('uuid', '=', $uuid)->first();

        return $row === null ? null : new static($row);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * A version 4 UUID. Every content row gets one so identifiers can be
     * exposed without leaking how many records exist.
     */
    public static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    // -------------------------------------------------------------------------
    // Attribute access
    // -------------------------------------------------------------------------

    public function __get(string $name): mixed
    {
        return $this->attribute($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $default;
        }

        if (array_key_exists($name, $this->casts)) {
            return $this->casts[$name];
        }

        $value = $this->attributes[$name];

        if (in_array($name, static::$jsonColumns, true)) {
            return $this->casts[$name] = self::decodeJson($value);
        }

        if (in_array($name, static::$dateColumns, true)) {
            return $this->casts[$name] = self::toDate($value);
        }

        return $value;
    }

    public function set(string $name, mixed $value): static
    {
        $this->attributes[$name] = $value;
        unset($this->casts[$name]);

        return $this;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function id(): int
    {
        return (int) ($this->attributes['id'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->id() > 0;
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->attributes[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->attributes[$name] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $name, ?float $default = null): ?float
    {
        $value = $this->attributes[$name] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->attributes[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        return in_array(is_string($value) ? strtolower($value) : $value, [1, '1', true, 'true', 'yes', 'on'], true);
    }

    public function date(string $name): ?\DateTimeImmutable
    {
        $value = $this->attribute($name);

        return $value instanceof \DateTimeImmutable ? $value : self::toDate($this->attributes[$name] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    public function json(string $name): array
    {
        $value = $this->attribute($name);

        return is_array($value) ? $value : [];
    }

    public function isDeleted(): bool
    {
        return ($this->attributes['deleted_at'] ?? null) !== null;
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $values
     */
    public function update(array $values): bool
    {
        if (!$this->exists()) {
            throw new \LogicException('Cannot update a record that has not been saved.');
        }

        if (static::hasUpdatedAtColumn()) {
            $values['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        $encoded = self::encodeForStorage($values, static::$jsonColumns);

        $affected = static::query()->where('id', '=', $this->id())->update($encoded);

        foreach ($encoded as $key => $value) {
            $this->set($key, $value);
        }

        return $affected > 0;
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function create(array $values): static
    {
        $now = gmdate('Y-m-d H:i:s');

        $values['created_at'] ??= $now;

        if (static::hasUpdatedAtColumn()) {
            $values['updated_at'] ??= $now;
        }

        if (!array_key_exists('uuid', $values) && static::hasUuidColumn()) {
            $values['uuid'] = self::newUuid();
        }

        $encoded = self::encodeForStorage($values, static::$jsonColumns);

        $id = static::query()->insert($encoded);

        $created = static::find($id);

        if ($created === null) {
            throw new \RuntimeException('Insert into ' . static::table() . ' succeeded but the row could not be read back.');
        }

        return $created;
    }

    /** Marks the record deleted without removing the row. */
    public function softDelete(): bool
    {
        return $this->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function restore(): bool
    {
        return $this->update(['deleted_at' => null]);
    }

    /** Removes the row for good. */
    public function forceDelete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        return static::query()->where('id', '=', $this->id())->delete() > 0;
    }

    /** Re-reads the row from the database. */
    public function fresh(): ?static
    {
        return $this->exists() ? static::find($this->id()) : null;
    }

    protected static function hasUuidColumn(): bool
    {
        return true;
    }

    /**
     * Whether the table carries an updated_at column.
     *
     * The default is yes, and create() and update() stamp it. A model whose
     * table deliberately has no such column overrides this — writing the stamp
     * anyway is an unknown-column error the moment the first row is inserted,
     * which is how tagging a trip broke in production while no test had ever
     * created a tag.
     */
    protected static function hasUpdatedAtColumn(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $values
     * @param list<string>        $jsonColumns
     *
     * @return array<string,mixed>
     */
    protected static function encodeForStorage(array $values, array $jsonColumns): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                continue;
            }

            if (in_array($key, $jsonColumns, true) && (is_array($value) || is_object($value))) {
                $values[$key] = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                continue;
            }

            if (is_bool($value)) {
                $values[$key] = $value ? 1 : 0;
            }
        }

        return $values;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = $this->attributes;

        foreach (static::$hidden as $key) {
            unset($out[$key]);
        }

        foreach (static::$jsonColumns as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = self::decodeJson($out[$key]);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->attributes;
    }

    // -------------------------------------------------------------------------
    // ArrayAccess, so a model can stand in for a row array inside a template
    // -------------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset], $this->casts[(string) $offset]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            // Every datetime column holds UTC; the timezone is applied when
            // the value is formatted for a specific reader.
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Generates a slug that does not collide with an existing row.
     *
     * @param int|null $ignoreId row allowed to already hold the slug (an edit)
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null, string $column = 'slug', ?callable $scope = null): string
    {
        $base = \MTL\Support\Str::slug($source);

        if ($base === '') {
            $base = 'item';
        }

        $candidate = $base;
        $suffix = 1;

        while (true) {
            $query = static::query()->where($column, '=', $candidate);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if ($scope !== null) {
                $scope($query);
            }

            if (!$query->exists()) {
                return $candidate;
            }

            ++$suffix;
            $candidate = $base . '-' . $suffix;

            // Give up on guessing after a while and fall back to randomness,
            // so a title that collides constantly cannot loop forever.
            if ($suffix > 50) {
                return $base . '-' . strtolower(\MTL\Support\Str::randomCode(6));
            }
        }
    }
}
