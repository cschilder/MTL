<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Database;
use MTL\Core\Translator;
use MTL\Markdown\Markdown;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Creating and editing the individual stops of a trip.
 */
final class StepService
{
    /**
     * @param array<string,mixed> $input validated
     */
    public static function create(Trip $trip, array $input, User $author): Step
    {
        $title = trim((string) ($input['title'] ?? ''));

        $input = self::geocodeWhenUnplaced($input);

        $rendered = Markdown::renderWithContext((string) ($input['body_md'] ?? ''));

        // A new stop goes to the end of the trip.
        $position = (int) (Step::query()->where('trip_id', '=', $trip->id())->max('position') ?? -1) + 1;

        $step = Step::create([
            'trip_id'       => $trip->id(),
            'user_id'       => $author->id(),
            'title'         => $title,
            'slug'          => Step::slugFor((string) ($input['slug'] ?? $title), $trip->id()),
            'body_md'       => $input['body_md'] ?? null,
            'body_html'     => $rendered['html'],
            'excerpt'       => Str::excerpt($rendered['text'], 500),
            'latitude'      => $input['latitude'] ?? null,
            'longitude'     => $input['longitude'] ?? null,
            'altitude_m'    => $input['altitude_m'] ?? null,
            'location_name' => mb_substr((string) ($input['location_name'] ?? ''), 0, 191, 'UTF-8'),
            'country_code'  => self::normaliseCountry($input['country_code'] ?? ''),
            'timezone'      => self::normaliseTimezone($input['timezone'] ?? ''),
            'occurred_at'   => $input['occurred_at'] ?? null,
            'occurred_end_at' => $input['occurred_end_at'] ?? null,
            'position'      => $position,
            'status'        => $input['status'] ?? Step::STATUS_DRAFT,
            'visibility'    => $input['visibility'] ?? 'inherit',
            'weather'       => mb_substr((string) ($input['weather'] ?? ''), 0, 500, 'UTF-8'),
            'temperature_c' => $input['temperature_c'] ?? null,
            'rating'        => self::normaliseRating($input['rating'] ?? null),
            'published_at'  => ($input['status'] ?? '') === Step::STATUS_PUBLISHED ? gmdate('Y-m-d H:i:s') : null,
        ]);

        self::afterWrite($trip, $step, $rendered['media']);

        AuditService::log('step.created', $step, ['trip' => $trip->string('title')]);

        return $step;
    }

    /**
     * @param array<string,mixed> $input validated
     */
    public static function update(Step $step, array $input): Step
    {
        if ($step->latitude() === null && $step->longitude() === null) {
            $input = self::geocodeWhenUnplaced($input, $step);
        }

        $before = $step->raw();
        $values = [];
        $mediaIds = [];

        if (array_key_exists('title', $input)) {
            $values['title'] = trim((string) $input['title']);
        }

        if (array_key_exists('slug', $input) && trim((string) $input['slug']) !== '') {
            $values['slug'] = Step::slugFor((string) $input['slug'], $step->int('trip_id'), $step->id());
        }

        if (array_key_exists('body_md', $input)) {
            $rendered = Markdown::renderWithContext((string) $input['body_md']);

            $values['body_md'] = $input['body_md'];
            $values['body_html'] = $rendered['html'];
            $values['excerpt'] = Str::excerpt($rendered['text'], 500);

            $mediaIds = $rendered['media'];
        }

        foreach ([
            'latitude', 'longitude', 'altitude_m', 'occurred_at', 'occurred_end_at',
            'cover_media_id', 'temperature_c',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }

        // NOT NULL column: null or empty means "not submitted", never "clear
        // it". See TripService::update for the incident.
        if (isset($input['visibility']) && $input['visibility'] !== '') {
            $values['visibility'] = $input['visibility'];
        }

        if (array_key_exists('location_name', $input)) {
            $values['location_name'] = mb_substr((string) $input['location_name'], 0, 191, 'UTF-8');
        }

        if (array_key_exists('weather', $input)) {
            $values['weather'] = mb_substr((string) $input['weather'], 0, 500, 'UTF-8');
        }

        if (array_key_exists('country_code', $input)) {
            $values['country_code'] = self::normaliseCountry($input['country_code']);
        }

        if (array_key_exists('timezone', $input)) {
            $values['timezone'] = self::normaliseTimezone($input['timezone']);
        }

        if (array_key_exists('rating', $input)) {
            $values['rating'] = self::normaliseRating($input['rating']);
        }

        if (array_key_exists('status', $input)) {
            $values['status'] = $input['status'];

            if ($input['status'] === Step::STATUS_PUBLISHED && $step->attribute('published_at') === null) {
                $values['published_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $step->update($values);

        $trip = $step->trip();

        if ($trip !== null) {
            self::afterWrite($trip, $step, $mediaIds);
        }

        AuditService::logChange('step.updated', $step, $before, $values);

        return $step;
    }

    /**
     * Work that follows any write: keep the trip's totals, the search index
     * and the media usage counters in step with the text.
     *
     * @param list<int> $referencedMediaIds media embedded in the report
     */
    private static function afterWrite(Trip $trip, Step $step, array $referencedMediaIds): void
    {
        // Photos referenced inline in the report are attached to the step as
        // well, so they appear in its gallery and count towards the totals.
        if ($referencedMediaIds !== []) {
            MediaService::attachToStep($step, $referencedMediaIds);
        }

        TripService::refreshAggregates($trip);
        TripService::refreshStepDistances($trip);

        SearchService::index($step);
    }

    public static function delete(Step $step): void
    {
        $trip = $step->trip();

        $step->softDelete();

        SearchService::remove('step', $step->id());

        if ($trip !== null) {
            TripService::refreshAggregates($trip);
        }

        AuditService::log('step.deleted', $step);
    }

    /**
     * Moves a step to a different trip, keeping its content.
     */
    public static function moveToTrip(Step $step, Trip $target): void
    {
        $source = $step->trip();

        $position = (int) (Step::query()->where('trip_id', '=', $target->id())->max('position') ?? -1) + 1;

        $step->update([
            'trip_id'  => $target->id(),
            'position' => $position,
            // The slug is only unique inside a trip, so it may have to change.
            'slug'     => Step::slugFor($step->string('title'), $target->id(), $step->id()),
        ]);

        if ($source !== null) {
            TripService::refreshAggregates($source);
        }

        TripService::refreshAggregates($target);
        SearchService::index($step);

        AuditService::log('step.moved', $step, [
            'from' => $source?->string('title'),
            'to'   => $target->string('title'),
        ]);
    }

    /**
     * The steps before and after this one, for the reading navigation.
     *
     * @return array{previous:?Step,next:?Step}
     */
    public static function neighbours(Step $step, bool $publishedOnly = true): array
    {
        $query = static function () use ($step, $publishedOnly) {
            $q = Step::active()->where('trip_id', '=', $step->int('trip_id'));

            if ($publishedOnly) {
                $q->where('status', '=', Step::STATUS_PUBLISHED);
            }

            return $q;
        };

        $position = $step->int('position');

        $previous = $query()
            ->where('position', '<', $position)
            ->orderBy('position', 'DESC')
            ->first();

        $next = $query()
            ->where('position', '>', $position)
            ->orderBy('position')
            ->first();

        return [
            'previous' => $previous === null ? null : Step::fromRow($previous),
            'next'     => $next === null ? null : Step::fromRow($next),
        ];
    }

    /**
     * Suggests where and when a step happened, from the photos attached to it.
     *
     * Used by the editor's "take the location from the photo" action.
     *
     * @return array{latitude:?float,longitude:?float,occurred_at:?string}
     */
    public static function suggestFromMedia(Step $step): array
    {
        $row = Database::instance()
            ->table('media')
            ->select('media.latitude', 'media.longitude', 'media.captured_at')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->where('step_media.step_id', '=', $step->id())
            ->whereNull('media.deleted_at')
            ->whereNotNull('media.latitude')
            ->orderBy('media.captured_at')
            ->first();

        $earliest = Database::instance()
            ->table('media')
            ->select('media.captured_at')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->where('step_media.step_id', '=', $step->id())
            ->whereNull('media.deleted_at')
            ->whereNotNull('media.captured_at')
            ->orderBy('media.captured_at')
            ->first();

        return [
            'latitude'    => $row === null ? null : (float) $row['latitude'],
            'longitude'   => $row === null ? null : (float) $row['longitude'],
            'occurred_at' => $earliest === null ? null : (string) $earliest['captured_at'],
        ];
    }

    /**
     * Gives an existing stop without coordinates its place on the globe.
     *
     * Everything the stop already carries is tried, cheapest first: the
     * geotag of its photos (local, exact, free), then the location name, then
     * the title — people type "Edinburgh" as a title at least as often as in
     * the location field. Returns whether the stop ended up placed.
     */
    public static function place(Step $step): bool
    {
        if ($step->latitude() !== null || $step->longitude() !== null) {
            return false;
        }

        $values = [];

        $suggestion = self::suggestFromMedia($step);

        if ($suggestion['latitude'] !== null) {
            $values = [
                'latitude'  => $suggestion['latitude'],
                'longitude' => $suggestion['longitude'],
            ];
        } else {
            foreach ([$step->string('location_name'), $step->string('title')] as $query) {
                $query = trim($query);

                if ($query === '') {
                    continue;
                }

                try {
                    $hit = GeocodeService::best($query, Translator::locale());
                } catch (\Throwable) {
                    $hit = null;
                }

                if ($hit === null) {
                    continue;
                }

                $values = [
                    'latitude'  => $hit['latitude'],
                    'longitude' => $hit['longitude'],
                ];

                if ($step->string('country_code') === '' && $hit['country'] !== '') {
                    $values['country_code'] = $hit['country'];
                }

                // A stop placed via its title gets the resolved place as its
                // location, so the public page can say where this was.
                if (trim($step->string('location_name')) === '') {
                    $values['location_name'] = mb_substr($hit['name'], 0, 191, 'UTF-8');
                }

                break;
            }
        }

        if ($values === []) {
            return false;
        }

        $step->update($values);

        $trip = $step->trip();

        if ($trip !== null) {
            TripService::refreshAggregates($trip);
            TripService::refreshStepDistances($trip);
        }

        SearchService::index($step);

        AuditService::log('step.placed', $step, [
            'latitude'  => $values['latitude'],
            'longitude' => $values['longitude'],
        ]);

        return true;
    }

    private static function normaliseCountry(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
    }

    private static function normaliseTimezone(mixed $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            return '';
        }

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : '';
    }

    /**
     * Fills in coordinates from the place name when the author gave none.
     *
     * "Rotterdam, Schiphol, Edinburgh" is how people think about a journey;
     * nobody knows latitudes by heart. When a stop arrives with a location
     * name and empty coordinate fields, the geocoder gets one shot at it —
     * best effort: a save must never fail or stall on the outside world, so an
     * unreachable geocoder simply leaves the fields empty and the management
     * screen keeps saying the stop is not on the globe yet.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private static function geocodeWhenUnplaced(array $input, ?Step $step = null): array
    {
        $hasCoordinates = ($input['latitude'] ?? null) !== null && ($input['latitude'] ?? '') !== ''
            && ($input['longitude'] ?? null) !== null && ($input['longitude'] ?? '') !== '';

        if ($hasCoordinates) {
            return $input;
        }

        $name = trim((string) ($input['location_name'] ?? ''));
        $previous = $step === null ? '' : trim($step->string('location_name'));

        // No location typed? The title works too: "Rotterdam" or "Falkirk" as
        // a stop title is at least as common as filling in the location field.
        if ($name === '') {
            $name = trim((string) ($input['title'] ?? ''));
            $previous = $step === null ? '' : trim($step->string('title'));
        }

        if ($name === '' || ($step !== null && $name === $previous)) {
            // Nothing to look up, or the same unplaced name as before — the
            // earlier lookup already failed and a save is not a retry loop.
            return $input;
        }

        try {
            $hit = GeocodeService::best($name, Translator::locale());
        } catch (\Throwable) {
            return $input;
        }

        if ($hit === null) {
            return $input;
        }

        $input['latitude'] = $hit['latitude'];
        $input['longitude'] = $hit['longitude'];

        if (trim((string) ($input['country_code'] ?? '')) === '' && $hit['country'] !== '') {
            $input['country_code'] = $hit['country'];
        }

        return $input;
    }

    private static function normaliseRating(mixed $rating): ?int
    {
        if ($rating === null || $rating === '') {
            return null;
        }

        $value = (int) $rating;

        return ($value >= 1 && $value <= 5) ? $value : null;
    }
}
