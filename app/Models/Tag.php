<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Core\Database;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * A label applied to trips, steps, albums or media.
 */
final class Tag extends Model
{
    protected static string $table = 'tags';

    /** @var list<string> */
    protected static array $dateColumns = ['created_at'];

    /** Tags have no uuid column; the slug is already a stable identifier. */
    protected static function hasUuidColumn(): bool
    {
        return false;
    }

    /** And no updated_at either: a tag is only ever created or counted. */
    protected static function hasUpdatedAtColumn(): bool
    {
        return false;
    }

    public function url(): string
    {
        return path('/search', ['tag' => $this->string('slug')]);
    }

    /**
     * Finds a tag by name, creating it when it does not exist yet.
     *
     * Matching is by slug, so "Zuid-Amerika", "zuid amerika" and
     * "Zuid Amerika" all land on the same tag.
     */
    public static function findOrCreate(string $name): self
    {
        $name = trim($name);
        $slug = Str::slug($name, 96);

        if ($slug === '') {
            throw new \InvalidArgumentException('A tag needs a name.');
        }

        $row = self::query()->where('slug', '=', $slug)->first();

        if ($row !== null) {
            return new self($row);
        }

        return self::create([
            'name'        => mb_substr($name, 0, 80),
            'slug'        => $slug,
            'usage_count' => 0,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Replaces the tags on a record with exactly $names.
     *
     * @param list<string> $names
     */
    public static function sync(string $subjectType, int $subjectId, array $names): void
    {
        $db = Database::instance();

        $db->transaction(static function () use ($db, $subjectType, $subjectId, $names): void {
            $wanted = [];

            foreach ($names as $name) {
                $name = trim($name);
                if ($name === '' || Str::slug($name) === '') {
                    continue;
                }
                $tag = self::findOrCreate($name);
                $wanted[$tag->id()] = true;
            }

            $existing = $db->table('taggables')
                ->where('taggable_type', '=', $subjectType)
                ->where('taggable_id', '=', $subjectId)
                ->pluck('tag_id');

            $existing = array_map('intval', $existing);

            foreach (array_diff($existing, array_keys($wanted)) as $removed) {
                $db->table('taggables')
                    ->where('taggable_type', '=', $subjectType)
                    ->where('taggable_id', '=', $subjectId)
                    ->where('tag_id', '=', $removed)
                    ->delete();
            }

            foreach (array_diff(array_keys($wanted), $existing) as $added) {
                $db->table('taggables')->insert([
                    'tag_id'        => $added,
                    'taggable_type' => $subjectType,
                    'taggable_id'   => $subjectId,
                    'created_at'    => gmdate('Y-m-d H:i:s'),
                ]);
            }

            // Refresh the counters for every tag involved either way.
            foreach (array_unique(array_merge($existing, array_keys($wanted))) as $tagId) {
                $count = $db->table('taggables')->where('tag_id', '=', $tagId)->count();
                $db->table('tags')->where('id', '=', $tagId)->update(['usage_count' => $count]);
            }
        });
    }

    /**
     * @return list<self>
     */
    public static function forSubject(string $subjectType, int $subjectId): array
    {
        $rows = Database::instance()
            ->table('tags')
            ->select('tags.*')
            ->join('taggables', 'taggables.tag_id', '=', 'tags.id')
            ->where('taggables.taggable_type', '=', $subjectType)
            ->where('taggables.taggable_id', '=', $subjectId)
            ->orderBy('tags.name')
            ->get();

        return self::fromRows($rows);
    }

    /**
     * @return list<self> most-used first
     */
    public static function popular(int $limit = 40): array
    {
        return self::fromRows(
            self::query()->where('usage_count', '>', 0)->orderBy('usage_count', 'DESC')->limit($limit)->get()
        );
    }

    /** Removes tags no longer applied to anything. */
    public static function pruneUnused(): int
    {
        return self::query()->where('usage_count', '=', 0)->delete();
    }
}
