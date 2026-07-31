<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Database;
use MTL\Core\QueryBuilder;
use MTL\Markdown\Markdown;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Reading and writing trips.
 */
final class TripService
{
    /**
     * Trips $user is allowed to see, newest journey first.
     *
     * @return list<Trip>
     */
    public static function visibleTrips(?User $user, int $limit = 60, int $offset = 0): array
    {
        $query = self::visibleQuery($user)
            ->orderBy('position')
            ->orderByRaw('COALESCE(`start_date`, `created_at`) DESC')
            ->limit($limit)
            ->offset($offset);

        return Trip::fromRows($query->get());
    }

    /**
     * The base query for "trips this visitor may list".
     *
     * Unlisted trips are deliberately excluded: they are reachable through
     * their share link, and appearing in a listing would defeat that.
     */
    public static function visibleQuery(?User $user): QueryBuilder
    {
        $query = Trip::active();

        if ($user === null) {
            return $query
                ->where('status', '=', Trip::STATUS_PUBLISHED)
                ->where('visibility', '=', Trip::VISIBILITY_PUBLIC);
        }

        if ($user->isEditor()) {
            return $query;
        }

        // An author sees everything public plus everything of their own.
        return $query->whereGroup(static function (QueryBuilder $q) use ($user): void {
            $q->whereGroup(static function (QueryBuilder $inner): void {
                $inner->where('status', '=', Trip::STATUS_PUBLISHED)
                    ->where('visibility', '=', Trip::VISIBILITY_PUBLIC);
            });

            $q->orWhere('user_id', '=', $user->id());
        });
    }

    /**
     * Recomputes everything a trip stores about its steps.
     *
     * Called after any change to the trip's steps. Cheap enough to run on
     * every save and it keeps listings to a single query.
     */
    public static function refreshAggregates(Trip $trip): void
    {
        $steps = Step::active()
            ->where('trip_id', '=', $trip->id())
            ->orderBy('position')
            ->orderBy('occurred_at')
            ->get();

        $countries = [];
        $distance = 0.0;
        $previous = null;
        $earliest = null;
        $latest = null;

        foreach ($steps as $row) {
            $step = Step::fromRow($row);

            $country = strtoupper($step->string('country_code'));

            if ($country !== '' && !in_array($country, $countries, true)) {
                $countries[] = $country;
            }

            if ($step->hasCoordinates()) {
                $current = [$step->latitude(), $step->longitude()];

                if ($previous !== null) {
                    $leg = Step::distanceBetween($previous[0], $previous[1], $current[0], $current[1]);

                    // A jump of more than half the planet between consecutive
                    // stops is almost always a typo in a coordinate, not a
                    // flight; counting it would make the total meaningless.
                    if ($leg < 20000) {
                        $distance += $leg;
                    }
                }

                $previous = $current;
            }

            $occurred = $step->date('occurred_at');

            if ($occurred !== null) {
                $earliest = $earliest === null ? $occurred : min($earliest, $occurred);
                $latest = $latest === null ? $occurred : max($latest, $occurred);
            }
        }

        $mediaCount = Database::instance()
            ->table('step_media')
            ->select('step_media.media_id')
            ->distinct()
            ->join('steps', 'steps.id', '=', 'step_media.step_id')
            ->join('media', 'media.id', '=', 'step_media.media_id')
            ->where('steps.trip_id', '=', $trip->id())
            ->whereNull('steps.deleted_at')
            ->whereNull('media.deleted_at')
            ->count('step_media.media_id');

        $values = [
            'step_count'    => count($steps),
            'media_count'   => $mediaCount,
            'distance_km'   => round($distance, 2),
            'country_codes' => implode(',', array_slice($countries, 0, 80)),
        ];

        // Dates are only filled in from the steps when the author has not set
        // them; an explicit range on the trip always wins.
        if ($trip->string('start_date') === '' && $earliest !== null) {
            $values['start_date'] = $earliest->format('Y-m-d');
        }

        if ($trip->string('end_date') === '' && $latest !== null) {
            $values['end_date'] = $latest->format('Y-m-d');
        }

        $trip->update($values);
    }

    /**
     * Creates a trip from validated input.
     *
     * @param array<string,mixed> $input
     */
    public static function create(array $input, User $author): Trip
    {
        $title = trim((string) ($input['title'] ?? ''));

        $rendered = Markdown::renderWithContext((string) ($input['body_md'] ?? ''));

        $trip = Trip::create([
            'user_id'      => $author->id(),
            'title'        => $title,
            'slug'         => Trip::slugFor($input['slug'] ?? $title),
            'summary'      => mb_substr((string) ($input['summary'] ?? ''), 0, 500, 'UTF-8'),
            'body_md'      => $input['body_md'] ?? null,
            'body_html'    => $rendered['html'],
            'start_date'   => $input['start_date'] ?? null,
            'end_date'     => $input['end_date'] ?? null,
            'status'       => $input['status'] ?? Trip::STATUS_DRAFT,
            'visibility'   => $input['visibility'] ?? Trip::VISIBILITY_PRIVATE,
            'color'        => self::normaliseColour($input['color'] ?? ''),
            'share_token'  => Str::randomCode(22),
            'published_at' => ($input['status'] ?? '') === Trip::STATUS_PUBLISHED ? gmdate('Y-m-d H:i:s') : null,
        ]);

        SearchService::index($trip);

        AuditService::log('trip.created', $trip);

        return $trip;
    }

    /**
     * Applies validated input to an existing trip.
     *
     * @param array<string,mixed> $input
     */
    public static function update(Trip $trip, array $input): Trip
    {
        $before = $trip->raw();
        $values = [];

        if (array_key_exists('title', $input)) {
            $values['title'] = trim((string) $input['title']);
        }

        if (array_key_exists('slug', $input) && trim((string) $input['slug']) !== '') {
            $values['slug'] = Trip::slugFor((string) $input['slug'], $trip->id());
        } elseif (isset($values['title']) && $trip->string('slug') === '') {
            $values['slug'] = Trip::slugFor($values['title'], $trip->id());
        }

        if (array_key_exists('summary', $input)) {
            $values['summary'] = mb_substr((string) $input['summary'], 0, 500, 'UTF-8');
        }

        if (array_key_exists('body_md', $input)) {
            $rendered = Markdown::renderWithContext((string) $input['body_md']);
            $values['body_md'] = $input['body_md'];
            $values['body_html'] = $rendered['html'];
        }

        foreach (['start_date', 'end_date', 'cover_media_id', 'visibility', 'position'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }

        if (array_key_exists('color', $input)) {
            $values['color'] = self::normaliseColour((string) $input['color']);
        }

        if (array_key_exists('status', $input)) {
            $values['status'] = $input['status'];

            // Stamp the publication moment the first time it goes live, and
            // leave it alone afterwards so the feed order stays stable.
            if ($input['status'] === Trip::STATUS_PUBLISHED && $trip->attribute('published_at') === null) {
                $values['published_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $trip->update($values);

        SearchService::index($trip);

        AuditService::logChange('trip.updated', $trip, $before, $values);

        return $trip;
    }

    public static function delete(Trip $trip): void
    {
        $trip->softDelete();

        SearchService::remove('trip', $trip->id());

        AuditService::log('trip.deleted', $trip);
    }

    public static function restore(Trip $trip): void
    {
        $trip->restore();

        SearchService::index($trip);

        AuditService::log('trip.restored', $trip);
    }

    /**
     * Applies a new order to a trip's steps.
     *
     * @param list<int> $stepIds
     */
    public static function reorderSteps(Trip $trip, array $stepIds): void
    {
        $db = Database::instance();

        $db->transaction(static function () use ($db, $trip, $stepIds): void {
            $position = 0;

            foreach ($stepIds as $stepId) {
                $db->table('steps')
                    ->where('id', '=', (int) $stepId)
                    ->where('trip_id', '=', $trip->id())
                    ->update(['position' => $position++]);
            }
        });

        // Ordering changes the route, so the distance has to be recomputed.
        self::refreshAggregates($trip);

        AuditService::log('trip.steps_reordered', $trip, ['count' => count($stepIds)]);
    }

    /**
     * Recalculates each step's distance from the one before it.
     */
    public static function refreshStepDistances(Trip $trip): void
    {
        $steps = Step::active()
            ->where('trip_id', '=', $trip->id())
            ->orderBy('position')
            ->orderBy('occurred_at')
            ->get();

        $previous = null;

        foreach ($steps as $row) {
            $step = Step::fromRow($row);

            if (!$step->hasCoordinates()) {
                continue;
            }

            $distance = 0.0;

            if ($previous !== null) {
                $distance = Step::distanceBetween($previous[0], $previous[1], $step->latitude(), $step->longitude());
            }

            $step->update(['distance_km' => round($distance, 2)]);

            $previous = [$step->latitude(), $step->longitude()];
        }
    }

    /**
     * A hex colour, or the default when the input is not one.
     */
    private static function normaliseColour(string $colour): string
    {
        $colour = trim($colour);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1) {
            return strtolower($colour);
        }

        if (preg_match('/^#[0-9a-fA-F]{3}$/', $colour) === 1) {
            // Expand the shorthand so the stored value always has one shape.
            return strtolower('#' . $colour[1] . $colour[1] . $colour[2] . $colour[2] . $colour[3] . $colour[3]);
        }

        return '#2ec27e';
    }

    /**
     * Statistics for the dashboard and the public overview.
     *
     * @return array{trips:int,steps:int,countries:int,distance_km:float,photos:int}
     */
    public static function statistics(?User $user = null): array
    {
        $tripIds = self::visibleQuery($user)->pluck('id');
        $tripIds = array_map('intval', $tripIds);

        if ($tripIds === []) {
            return ['trips' => 0, 'steps' => 0, 'countries' => 0, 'distance_km' => 0.0, 'photos' => 0];
        }

        $db = Database::instance();

        $countries = [];

        foreach ($db->table('trips')->whereIn('id', $tripIds)->pluck('country_codes') as $codes) {
            foreach (explode(',', (string) $codes) as $code) {
                $code = trim($code);
                if ($code !== '') {
                    $countries[$code] = true;
                }
            }
        }

        return [
            'trips'       => count($tripIds),
            'steps'       => (int) $db->table('steps')->whereIn('trip_id', $tripIds)->whereNull('deleted_at')->count(),
            'countries'   => count($countries),
            'distance_km' => round((float) $db->table('trips')->whereIn('id', $tripIds)->sum('distance_km'), 1),
            'photos'      => (int) $db->table('trips')->whereIn('id', $tripIds)->sum('media_count'),
        ];
    }
}
