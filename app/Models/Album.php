<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Core\Database;

defined('MTL_APP') || exit;

/**
 * An ordered set of media, optionally belonging to a trip or a single step.
 */
final class Album extends Model
{
    protected static string $table = 'albums';

    public const LAYOUT_GRID = 'grid';
    public const LAYOUT_MASONRY = 'masonry';
    public const LAYOUT_STORY = 'story';

    public function isPublished(): bool
    {
        return $this->string('status') === 'published';
    }

    public function isVisibleTo(?User $user): bool
    {
        if ($this->isDeleted()) {
            return $user !== null && $user->isEditor();
        }

        if ($user !== null && ($user->isEditor() || $this->int('user_id') === $user->id())) {
            return true;
        }

        if (!$this->isPublished()) {
            return false;
        }

        $visibility = $this->string('visibility', 'inherit');

        if ($visibility === 'public') {
            return true;
        }

        if ($visibility === 'private') {
            return false;
        }

        if ($visibility === 'unlisted') {
            // Reachable through its share link, which the controller checks;
            // not through a listing.
            return false;
        }

        // 'inherit': follow whatever the album hangs from. A standalone album
        // with inherited visibility is treated as public once published, since
        // there is nothing to inherit from.
        $tripId = $this->int('trip_id');

        if ($tripId > 0) {
            $trip = Trip::find($tripId);

            return $trip !== null && $trip->isVisibleTo($user);
        }

        $stepId = $this->int('step_id');

        if ($stepId > 0) {
            $step = Step::find($stepId);

            return $step !== null && $step->isVisibleTo($user);
        }

        return true;
    }

    public function url(): string
    {
        return path('/albums/' . $this->string('slug'));
    }

    public function editUrl(): string
    {
        return path('/admin/albums/' . $this->id());
    }

    public function layout(): string
    {
        $layout = $this->string('layout', self::LAYOUT_GRID);

        return in_array($layout, [self::LAYOUT_GRID, self::LAYOUT_MASONRY, self::LAYOUT_STORY], true)
            ? $layout
            : self::LAYOUT_GRID;
    }

    /**
     * @return list<Media>
     */
    public function media(int $limit = 0): array
    {
        $query = Database::instance()
            ->table('media')
            ->select('media.*', 'album_media.caption AS pivot_caption')
            ->join('album_media', 'album_media.media_id', '=', 'media.id')
            ->where('album_media.album_id', '=', $this->id())
            ->whereNull('media.deleted_at')
            ->orderBy('album_media.position')
            ->orderBy('media.captured_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return Media::fromRows($query->get());
    }

    public function coverMedia(): ?Media
    {
        $id = $this->int('cover_media_id');

        if ($id > 0) {
            $media = Media::find($id);

            if ($media !== null && !$media->isDeleted()) {
                return $media;
            }
        }

        return $this->media(1)[0] ?? null;
    }

    /** What this album belongs to, for the breadcrumb. */
    public function parent(): Trip|Step|null
    {
        $stepId = $this->int('step_id');

        if ($stepId > 0) {
            return Step::find($stepId);
        }

        $tripId = $this->int('trip_id');

        return $tripId > 0 ? Trip::find($tripId) : null;
    }

    public static function findBySlug(string $slug): ?self
    {
        $row = self::query()->where('slug', '=', $slug)->first();

        return $row === null ? null : new self($row);
    }
}
