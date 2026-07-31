<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Markdown\Markdown;
use MTL\Models\Album;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Creating and editing albums.
 */
final class AlbumService
{
    /**
     * @param array<string,mixed> $input validated
     */
    public static function create(array $input, User $author): Album
    {
        $title = trim((string) ($input['title'] ?? ''));

        $album = Album::create([
            'user_id'          => $author->id(),
            'trip_id'          => self::nullableId($input['trip_id'] ?? null),
            'step_id'          => self::nullableId($input['step_id'] ?? null),
            'title'            => $title,
            'slug'             => Album::uniqueSlug((string) ($input['slug'] ?? $title)),
            'description_md'   => $input['description_md'] ?? null,
            'description_html' => Markdown::renderSnippet((string) ($input['description_md'] ?? '')),
            'status'           => $input['status'] ?? 'draft',
            'visibility'       => $input['visibility'] ?? 'inherit',
            'layout'           => self::layout($input['layout'] ?? Album::LAYOUT_GRID),
            'share_token'      => Str::randomCode(22),
        ]);

        SearchService::index($album);

        AuditService::log('album.created', $album);

        return $album;
    }

    /**
     * @param array<string,mixed> $input validated
     */
    public static function update(Album $album, array $input): Album
    {
        $before = $album->raw();
        $values = [];

        if (array_key_exists('title', $input)) {
            $values['title'] = trim((string) $input['title']);
        }

        if (array_key_exists('slug', $input) && trim((string) $input['slug']) !== '') {
            $values['slug'] = Album::uniqueSlug((string) $input['slug'], $album->id());
        }

        if (array_key_exists('description_md', $input)) {
            $values['description_md'] = $input['description_md'];
            $values['description_html'] = Markdown::renderSnippet((string) $input['description_md']);
        }

        if (array_key_exists('cover_media_id', $input)) {
            $values['cover_media_id'] = $input['cover_media_id'];
        }

        // NOT NULL columns: null or empty means "not submitted", never "clear
        // it" — writing the null through is what broke album saves in
        // production. See TripService::update.
        foreach (['status', 'visibility', 'position'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $values[$field] = $input[$field];
            }
        }

        if (array_key_exists('layout', $input)) {
            $values['layout'] = self::layout($input['layout']);
        }

        foreach (['trip_id', 'step_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = self::nullableId($input[$field]);
            }
        }

        $album->update($values);

        SearchService::index($album);

        AuditService::logChange('album.updated', $album, $before, $values);

        return $album;
    }

    public static function delete(Album $album): void
    {
        $album->softDelete();

        SearchService::remove('album', $album->id());

        AuditService::log('album.deleted', $album);
    }

    private static function layout(mixed $layout): string
    {
        $layout = (string) $layout;

        return in_array($layout, [Album::LAYOUT_GRID, Album::LAYOUT_MASONRY, Album::LAYOUT_STORY], true)
            ? $layout
            : Album::LAYOUT_GRID;
    }

    /** An empty select submits '', which has to become NULL rather than 0. */
    private static function nullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
