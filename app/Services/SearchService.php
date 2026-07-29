<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Database;
use MTL\Models\Album;
use MTL\Models\Media;
use MTL\Models\Model;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Full-text search over trips, steps and albums.
 *
 * Everything searchable is denormalised into one table with a FULLTEXT index.
 * That keeps ranking in one place, lets a single query cover every content
 * type, and means a private record can be filtered out before any join.
 *
 * MySQL's natural-language mode ignores words shorter than
 * `innodb_ft_min_token_size` (three characters by default) and words that
 * appear in more than half the rows. On a small site that second rule throws
 * away most useful terms, so short queries fall back to a LIKE scan, which is
 * perfectly fast at this scale.
 */
final class SearchService
{
    /** Below this many rows, boolean-mode ranking is not meaningful. */
    private const FULLTEXT_THRESHOLD = 25;

    /**
     * Adds or refreshes one record's entry.
     */
    public static function index(Model $subject): void
    {
        $entry = self::describe($subject);

        if ($entry === null) {
            return;
        }

        try {
            Database::instance()->table('search_index')->upsert(
                $entry + ['updated_at' => gmdate('Y-m-d H:i:s')],
                ['title', 'body', 'url', 'visibility', 'owner_id', 'occurred_at', 'updated_at']
            );
        } catch (\Throwable $e) {
            // Search is a convenience; a failure here must not stop a save.
            logger()->warning('Search indexing failed', [
                'subject' => $subject::class,
                'id'      => $subject->id(),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public static function remove(string $type, int $id): void
    {
        try {
            Database::instance()
                ->table('search_index')
                ->where('subject_type', '=', $type)
                ->where('subject_id', '=', $id)
                ->delete();
        } catch (\Throwable) {
            // Nothing to do: a stale entry is filtered out at read time
            // because the source row is gone.
        }
    }

    /**
     * Turns a record into an index entry.
     *
     * @return array<string,mixed>|null
     */
    private static function describe(Model $subject): ?array
    {
        if ($subject instanceof Trip) {
            return [
                'subject_type' => 'trip',
                'subject_id'   => $subject->id(),
                'title'        => $subject->string('title'),
                'body'         => trim($subject->string('summary') . "\n" . Str::stripMarkdown($subject->string('body_md'))),
                'url'          => $subject->url(),
                'visibility'   => self::visibilityOf($subject),
                'owner_id'     => $subject->int('user_id') ?: null,
                'occurred_at'  => $subject->string('start_date') !== '' ? $subject->string('start_date') . ' 00:00:00' : null,
            ];
        }

        if ($subject instanceof Step) {
            $trip = $subject->trip();

            return [
                'subject_type' => 'step',
                'subject_id'   => $subject->id(),
                'title'        => $subject->string('title'),
                'body'         => trim(
                    $subject->string('location_name') . "\n"
                    . Str::stripMarkdown($subject->string('body_md'))
                ),
                'url'          => $subject->url($trip),
                'visibility'   => self::stepVisibility($subject, $trip),
                'owner_id'     => $subject->int('user_id') ?: null,
                'occurred_at'  => $subject->string('occurred_at') ?: null,
            ];
        }

        if ($subject instanceof Album) {
            return [
                'subject_type' => 'album',
                'subject_id'   => $subject->id(),
                'title'        => $subject->string('title'),
                'body'         => Str::stripMarkdown($subject->string('description_md')),
                'url'          => $subject->url(),
                'visibility'   => $subject->isPublished() && in_array($subject->string('visibility'), ['public', 'inherit'], true)
                    ? 'public'
                    : 'private',
                'owner_id'     => $subject->int('user_id') ?: null,
                'occurred_at'  => null,
            ];
        }

        if ($subject instanceof Media) {
            // Only media that carries text worth finding is indexed; a library
            // full of untitled photos would swamp every result list.
            $text = trim($subject->string('title') . ' ' . $subject->string('alt_text') . ' ' . Str::stripMarkdown($subject->string('caption_md')));

            if ($text === '') {
                return null;
            }

            return [
                'subject_type' => 'media',
                'subject_id'   => $subject->id(),
                'title'        => $subject->displayTitle(),
                'body'         => $text,
                'url'          => $subject->url('medium'),
                'visibility'   => $subject->string('visibility') === 'public' ? 'public' : 'private',
                'owner_id'     => $subject->int('user_id') ?: null,
                'occurred_at'  => $subject->string('captured_at') ?: null,
            ];
        }

        return null;
    }

    private static function visibilityOf(Trip $trip): string
    {
        if (!$trip->isPublished()) {
            return 'private';
        }

        return match ($trip->string('visibility')) {
            'public'   => 'public',
            'unlisted' => 'unlisted',
            default    => 'private',
        };
    }

    private static function stepVisibility(Step $step, ?Trip $trip): string
    {
        if (!$step->isPublished()) {
            return 'private';
        }

        $own = $step->string('visibility', 'inherit');

        if ($own === 'private') {
            return 'private';
        }

        if ($own === 'public') {
            return 'public';
        }

        return $trip === null ? 'private' : self::visibilityOf($trip);
    }

    /**
     * Runs a search.
     *
     * @param list<string> $types restrict to these subject types
     *
     * @return array{results:list<array<string,mixed>>,total:int,mode:string}
     */
    public static function search(string $query, ?User $user, array $types = [], int $limit = 30, int $offset = 0): array
    {
        $query = trim($query);

        if (mb_strlen($query, 'UTF-8') < 2) {
            return ['results' => [], 'total' => 0, 'mode' => 'none'];
        }

        $useFulltext = self::shouldUseFulltext($query);

        try {
            return $useFulltext
                ? self::fulltextSearch($query, $user, $types, $limit, $offset)
                : self::likeSearch($query, $user, $types, $limit, $offset);
        } catch (\Throwable $e) {
            // An engine without FULLTEXT support, or a query MySQL rejects.
            logger()->notice('Falling back to LIKE search', ['error' => $e->getMessage()]);

            return self::likeSearch($query, $user, $types, $limit, $offset);
        }
    }

    private static function shouldUseFulltext(string $query): bool
    {
        // Every word shorter than the index's minimum token size would be
        // ignored, which produces an empty result for a perfectly good query.
        foreach (preg_split('/\s+/u', $query) ?: [] as $word) {
            if (mb_strlen(trim($word, '"*+-'), 'UTF-8') < 3) {
                return false;
            }
        }

        try {
            $rows = (int) Database::instance()->table('search_index')->count();
        } catch (\Throwable) {
            return false;
        }

        return $rows >= self::FULLTEXT_THRESHOLD;
    }

    /**
     * @param list<string> $types
     *
     * @return array{results:list<array<string,mixed>>,total:int,mode:string}
     */
    private static function fulltextSearch(string $query, ?User $user, array $types, int $limit, int $offset): array
    {
        $db = Database::instance();
        $prefix = $db->prefix();

        $expression = self::toBooleanExpression($query);

        [$visibilitySql, $visibilityBindings] = self::visibilityClause($user);

        $typeSql = '';
        $typeBindings = [];

        if ($types !== []) {
            $typeSql = ' AND subject_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $typeBindings = $types;
        }

        $where = 'MATCH(title, body) AGAINST (? IN BOOLEAN MODE) AND ' . $visibilitySql . $typeSql;

        $countBindings = array_merge([$expression], $visibilityBindings, $typeBindings);

        $total = (int) $db->scalar(
            "SELECT COUNT(*) FROM `{$prefix}search_index` WHERE {$where}",
            $countBindings
        );

        // The title match is weighted separately: a query that names a trip
        // should put that trip first, not a step that mentions it in passing.
        $rows = $db->select(
            "SELECT subject_type, subject_id, title, url, occurred_at,
                    SUBSTRING(body, 1, 400) AS snippet,
                    MATCH(title, body) AGAINST (? IN BOOLEAN MODE) AS score,
                    MATCH(title, body) AGAINST (? IN BOOLEAN MODE) * 2 AS title_score
             FROM `{$prefix}search_index`
             WHERE {$where}
             ORDER BY (score + title_score) DESC, occurred_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            array_merge([$expression, $expression, $expression], $visibilityBindings, $typeBindings)
        );

        return [
            'results' => self::decorate($rows, $query),
            'total'   => $total,
            'mode'    => 'fulltext',
        ];
    }

    /**
     * @param list<string> $types
     *
     * @return array{results:list<array<string,mixed>>,total:int,mode:string}
     */
    private static function likeSearch(string $query, ?User $user, array $types, int $limit, int $offset): array
    {
        $db = Database::instance();

        $builder = $db->table('search_index')
            ->select('subject_type', 'subject_id', 'title', 'url', 'occurred_at')
            ->selectRaw('SUBSTRING(`body`, 1, 400) AS snippet')
            ->whereGroup(static function ($q) use ($query): void {
                $q->whereLike('title', $query)->whereLike('body', $query, 'OR');
            });

        self::applyVisibility($builder, $user);

        if ($types !== []) {
            $builder->whereIn('subject_type', $types);
        }

        $total = $builder->count();

        $rows = $builder
            // Title matches first, then most recent.
            ->orderByRaw('CASE WHEN `title` LIKE ' . $db->pdo()->quote('%' . $query . '%') . ' THEN 0 ELSE 1 END')
            ->orderBy('occurred_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return [
            'results' => self::decorate($rows, $query),
            'total'   => $total,
            'mode'    => 'like',
        ];
    }

    /**
     * Restricts results to what this visitor is allowed to see.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private static function visibilityClause(?User $user): array
    {
        if ($user === null) {
            return ["visibility = 'public'", []];
        }

        if ($user->isEditor()) {
            return ['1 = 1', []];
        }

        return ["(visibility = 'public' OR owner_id = ?)", [$user->id()]];
    }

    private static function applyVisibility(\MTL\Core\QueryBuilder $builder, ?User $user): void
    {
        if ($user === null) {
            $builder->where('visibility', '=', 'public');

            return;
        }

        if ($user->isEditor()) {
            return;
        }

        $builder->whereGroup(static function ($q) use ($user): void {
            $q->where('visibility', '=', 'public')->orWhere('owner_id', '=', $user->id());
        });
    }

    /**
     * Escapes a user query for BOOLEAN MODE.
     *
     * The operators (+ - * " ~ < > ( )) are stripped rather than passed
     * through: a stray `-` or an unbalanced quote makes MySQL return nothing
     * or raise an error, and visitors do not type boolean syntax on purpose.
     */
    private static function toBooleanExpression(string $query): string
    {
        $words = preg_split('/\s+/u', $query) ?: [];
        $terms = [];

        foreach ($words as $word) {
            $clean = preg_replace('/[+\-><()~*"@]+/u', '', $word) ?? '';

            if (mb_strlen($clean, 'UTF-8') < 2) {
                continue;
            }

            // Every word must be present, and a trailing wildcard catches
            // plurals and inflections.
            $terms[] = '+' . $clean . '*';

            if (count($terms) >= 12) {
                break;
            }
        }

        return implode(' ', $terms);
    }

    /**
     * Adds a highlighted snippet to each row.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>>
     */
    private static function decorate(array $rows, string $query): array
    {
        $words = array_filter(
            preg_split('/\s+/u', $query) ?: [],
            static fn (string $w): bool => mb_strlen($w, 'UTF-8') >= 2
        );

        foreach ($rows as $index => $row) {
            $body = (string) ($row['snippet'] ?? '');

            $rows[$index]['snippet'] = self::highlight($body, $words);
            $rows[$index]['title_html'] = self::highlight((string) ($row['title'] ?? ''), $words);
        }

        return $rows;
    }

    /**
     * Escapes the text and wraps matches in <mark>.
     *
     * @param list<string> $words
     */
    private static function highlight(string $text, array $words): string
    {
        $escaped = htmlspecialchars(Str::excerpt($text, 240), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach ($words as $word) {
            $needle = preg_quote(htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');

            $escaped = preg_replace(
                '/(' . $needle . ')/iu',
                '<mark>$1</mark>',
                $escaped
            ) ?? $escaped;
        }

        return $escaped;
    }

    /**
     * Rebuilds the whole index.
     *
     * @return int number of entries written
     */
    public static function reindex(?callable $onProgress = null): int
    {
        $db = Database::instance();

        $db->table('search_index')->truncate();

        $count = 0;

        foreach ([Trip::class, Step::class, Album::class, Media::class] as $model) {
            /** @var class-string<Model> $model */
            $model::active()->chunk(200, static function (array $rows) use ($model, &$count, $onProgress): void {
                foreach ($rows as $row) {
                    self::index($model::fromRow($row));
                    ++$count;

                    if ($onProgress !== null && $count % 50 === 0) {
                        $onProgress($count);
                    }
                }
            });
        }

        return $count;
    }
}
