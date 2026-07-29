<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Core\Database;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * One stop on a trip: a place, a moment, and the report written about it.
 *
 * This is what the globe plots and what a marker opens.
 */
final class Step extends Model
{
    protected static string $table = 'steps';

    /** @var list<string> */
    protected static array $dateColumns = [
        'created_at', 'updated_at', 'deleted_at', 'published_at',
        'occurred_at', 'occurred_end_at',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function isPublished(): bool
    {
        return $this->string('status') === self::STATUS_PUBLISHED;
    }

    public function hasCoordinates(): bool
    {
        return $this->attribute('latitude') !== null && $this->attribute('longitude') !== null;
    }

    public function latitude(): ?float
    {
        return $this->float('latitude');
    }

    public function longitude(): ?float
    {
        return $this->float('longitude');
    }

    /**
     * Visibility follows the trip unless the step overrides it.
     */
    public function isVisibleTo(?User $user, ?Trip $trip = null): bool
    {
        if ($this->isDeleted()) {
            return $user !== null && $user->isEditor();
        }

        $own = $user !== null && $this->int('user_id') === $user->id();

        if ($user !== null && ($user->isEditor() || $own)) {
            return true;
        }

        if (!$this->isPublished()) {
            return false;
        }

        $visibility = $this->string('visibility', 'inherit');

        if ($visibility === 'private') {
            return false;
        }

        if ($visibility === 'public') {
            return true;
        }

        $trip ??= $this->trip();

        return $trip !== null && $trip->isVisibleTo($user);
    }

    public function trip(): ?Trip
    {
        $id = $this->int('trip_id');

        return $id > 0 ? Trip::find($id) : null;
    }

    public function url(?Trip $trip = null): string
    {
        $trip ??= $this->trip();

        if ($trip === null) {
            return path('/');
        }

        return path('/trips/' . $trip->string('slug') . '/' . $this->string('slug'));
    }

    public function editUrl(): string
    {
        return path('/admin/steps/' . $this->id());
    }

    /**
     * Media attached to this step, in gallery order.
     *
     * @return list<Media>
     */
    public function media(): array
    {
        $rows = Database::instance()
            ->table('media')
            ->select('media.*', 'step_media.caption AS pivot_caption', 'step_media.position AS pivot_position')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->where('step_media.step_id', '=', $this->id())
            ->whereNull('media.deleted_at')
            ->orderBy('step_media.position')
            ->orderBy('media.captured_at')
            ->get();

        return Media::fromRows($rows);
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

        $row = Database::instance()
            ->table('media')
            ->select('media.*')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->where('step_media.step_id', '=', $this->id())
            ->where('media.kind', '=', 'image')
            ->whereNull('media.deleted_at')
            ->orderBy('step_media.position')
            ->first();

        return $row === null ? null : Media::fromRow($row);
    }

    /**
     * A short plain-text summary, generated from the report when the author
     * has not written one.
     */
    public function excerpt(int $length = 200): string
    {
        $stored = $this->string('excerpt');

        if ($stored !== '') {
            return Str::excerpt($stored, $length);
        }

        return Str::excerpt(Str::stripMarkdown($this->string('body_md')), $length);
    }

    /**
     * The date, formatted in the reader's own time zone rather than UTC.
     */
    public function occurredLabel(?\DateTimeZone $timezone = null): string
    {
        $when = $this->date('occurred_at');

        if ($when === null) {
            return '';
        }

        // A step carries the time zone of the place it happened; that is more
        // meaningful than the reader's when the two differ.
        $stepZone = $this->string('timezone');

        if ($stepZone !== '') {
            try {
                $timezone = new \DateTimeZone($stepZone);
            } catch (\Exception) {
                // Fall through to the supplied zone.
            }
        }

        return $when->setTimezone($timezone ?? new \DateTimeZone(date_default_timezone_get()))
            ->format('j M Y, H:i');
    }

    /** A slug unique within the trip. */
    public static function slugFor(string $title, int $tripId, ?int $ignoreId = null): string
    {
        return self::uniqueSlug(
            $title,
            $ignoreId,
            'slug',
            static fn ($query) => $query->where('trip_id', '=', $tripId)
        );
    }

    public static function findBySlug(int $tripId, string $slug): ?self
    {
        $row = self::query()
            ->where('trip_id', '=', $tripId)
            ->where('slug', '=', $slug)
            ->first();

        return $row === null ? null : new self($row);
    }

    /**
     * Great-circle distance between two coordinates, in kilometres.
     *
     * The haversine formula: accurate to a few metres over the distances a
     * trip covers, and cheap enough to run for every step on every save.
     */
    public static function distanceBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0088;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
