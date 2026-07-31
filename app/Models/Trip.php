<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Core\Database;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * A journey: a titled, dated container for an ordered series of steps.
 */
final class Trip extends Model
{
    protected static string $table = 'trips';

    /** @var list<string> */
    protected static array $dateColumns = ['created_at', 'updated_at', 'deleted_at', 'published_at'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_UNLISTED = 'unlisted';
    public const VISIBILITY_PRIVATE = 'private';

    public function isPublished(): bool
    {
        return $this->string('status') === self::STATUS_PUBLISHED;
    }

    public function isPublic(): bool
    {
        return $this->isPublished() && $this->string('visibility') === self::VISIBILITY_PUBLIC;
    }

    /**
     * Whether $user may read this trip.
     *
     * Public published trips are open to everyone; an unlisted trip is opened
     * by its share token, which the controller verifies separately; everything
     * else needs an account with the right to see private content, or
     * ownership.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->isDeleted()) {
            return $user !== null && $user->isEditor();
        }

        if ($this->isPublic()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->isEditor() || $this->int('user_id') === $user->id();
    }

    public function url(): string
    {
        return path('/trips/' . $this->string('slug'));
    }

    public function shareUrl(): ?string
    {
        $token = $this->string('share_token');

        return $token === '' ? null : url('/s/' . $token);
    }

    public function editUrl(): string
    {
        return path('/admin/trips/' . $this->id());
    }

    /** ISO 3166-1 alpha-2 codes, decoded from the denormalised column. */
    public function countryCodes(): array
    {
        $raw = $this->string('country_codes');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * "12 Mar – 4 Apr 2026", or a single date when the trip lasted one day.
     */
    public function dateRange(): string
    {
        $start = $this->string('start_date');
        $end = $this->string('end_date');

        if ($start === '') {
            return '';
        }

        $startDate = date_create_immutable($start);
        if ($startDate === false) {
            return '';
        }

        if ($end === '' || $end === $start) {
            return $startDate->format('j M Y');
        }

        $endDate = date_create_immutable($end);
        if ($endDate === false) {
            return $startDate->format('j M Y');
        }

        // Drop the repeated year, and the repeated month within one month.
        if ($startDate->format('Y') === $endDate->format('Y')) {
            $left = $startDate->format('Y-m') === $endDate->format('Y-m')
                ? $startDate->format('j')
                : $startDate->format('j M');

            return $left . ' – ' . $endDate->format('j M Y');
        }

        return $startDate->format('j M Y') . ' – ' . $endDate->format('j M Y');
    }

    public function durationDays(): int
    {
        $start = $this->string('start_date');
        $end = $this->string('end_date');

        if ($start === '' || $end === '') {
            return 0;
        }

        $from = date_create_immutable($start);
        $to = date_create_immutable($end);

        if ($from === false || $to === false) {
            return 0;
        }

        return (int) $from->diff($to)->days + 1;
    }

    /**
     * The steps of this trip in reading order.
     *
     * @return list<Step>
     */
    public function steps(bool $publishedOnly = false): array
    {
        $query = Step::active()
            ->where('trip_id', '=', $this->id())
            ->orderBy('position')
            ->orderBy('occurred_at');

        if ($publishedOnly) {
            $query->where('status', '=', Step::STATUS_PUBLISHED);
        }

        return Step::fromRows($query->get());
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

        // Fall back to the first photo of the first step that has one, so a
        // trip card is never blank just because no cover was chosen.
        $row = Database::instance()
            ->table('media')
            ->select('media.*')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->join('steps', 'steps.id', '=', 'step_media.step_id')
            ->where('steps.trip_id', '=', $this->id())
            ->whereNull('media.deleted_at')
            ->whereNull('steps.deleted_at')
            ->where('media.kind', '=', 'image')
            ->orderBy('steps.position')
            ->orderBy('step_media.position')
            ->first();

        return $row === null ? null : Media::fromRow($row);
    }

    public function excerpt(int $length = 180): string
    {
        $summary = $this->string('summary');

        if ($summary !== '') {
            return Str::excerpt($summary, $length);
        }

        return Str::excerpt(Str::stripMarkdown($this->string('body_md')), $length);
    }

    /** A slug unique across trips. */
    public static function slugFor(string $title, ?int $ignoreId = null): string
    {
        return self::uniqueSlug($title, $ignoreId);
    }

    public static function findBySlug(string $slug): ?self
    {
        $row = self::query()->where('slug', '=', $slug)->first();

        return $row === null ? null : new self($row);
    }

    public static function findByShareToken(string $token): ?self
    {
        if (strlen($token) < 10) {
            return null;
        }

        $row = self::query()->where('share_token', '=', $token)->whereNull('deleted_at')->first();

        return $row === null ? null : new self($row);
    }
}
