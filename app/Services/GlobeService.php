<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Database;
use MTL\Models\Media;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;

defined('MTL_APP') || exit;

/**
 * The data behind the globe.
 *
 * The renderer needs one compact payload: every visible trip, its colour, and
 * its stops with coordinates, timestamps and the few values the extra
 * dimensions are keyed on. Names are short because this is fetched on every
 * visit to the home page and the difference across a hundred stops is real.
 *
 * The five dimensions the globe presents:
 *   1-3  position on the sphere (latitude, longitude, altitude)
 *   4    time — the scrubber reveals stops as their moment arrives
 *   5    a chosen measure — photo count, rating, altitude or temperature —
 *        which drives marker height and colour
 */
final class GlobeService
{
    /**
     * The full payload.
     *
     * @return array{
     *   generated: string,
     *   bounds: array{from:?string,to:?string},
     *   trips: list<array<string,mixed>>,
     *   stats: array<string,int|float>
     * }
     */
    public static function payload(?User $user, ?int $onlyTripId = null): array
    {
        $tripsQuery = TripService::visibleQuery($user)
            ->orderBy('position')
            ->orderByRaw('COALESCE(`start_date`, `created_at`)');

        if ($onlyTripId !== null) {
            $tripsQuery->where('id', '=', $onlyTripId);
        }

        $tripRows = $tripsQuery->get();

        if ($tripRows === []) {
            return [
                'generated' => gmdate('c'),
                'bounds'    => ['from' => null, 'to' => null],
                'trips'     => [],
                'stats'     => ['trips' => 0, 'steps' => 0, 'countries' => 0, 'distance_km' => 0],
            ];
        }

        $tripIds = array_map(static fn (array $row): int => (int) $row['id'], $tripRows);

        $steps = self::loadSteps($tripIds, $user);
        $covers = self::loadCoverThumbnails($steps);

        $trips = [];
        $earliest = null;
        $latest = null;
        $countries = [];

        foreach ($tripRows as $row) {
            $trip = Trip::fromRow($row);
            $tripSteps = [];

            foreach ($steps[$trip->id()] ?? [] as $step) {
                $occurred = $step->date('occurred_at');

                if ($occurred !== null) {
                    $timestamp = $occurred->getTimestamp();
                    $earliest = $earliest === null ? $timestamp : min($earliest, $timestamp);
                    $latest = $latest === null ? $timestamp : max($latest, $timestamp);
                }

                $country = strtoupper($step->string('country_code'));

                if ($country !== '') {
                    $countries[$country] = true;
                }

                $tripSteps[] = [
                    'id'    => $step->id(),
                    'slug'  => $step->string('slug'),
                    'title' => $step->string('title'),
                    // Rounded to seven decimals: about a centimetre, far
                    // beyond what a marker needs, and it keeps the payload small.
                    'lat'   => round((float) $step->latitude(), 5),
                    'lon'   => round((float) $step->longitude(), 5),
                    'alt'   => $step->float('altitude_m') === null ? null : round((float) $step->float('altitude_m')),
                    't'     => $occurred?->getTimestamp(),
                    'place' => $step->string('location_name'),
                    'cc'    => $country,
                    'url'   => $step->url($trip),
                    // The measures the fifth dimension can be keyed on.
                    'photos' => $step->int('media_count'),
                    'rating' => $step->attribute('rating') === null ? null : $step->int('rating'),
                    'temp'   => $step->float('temperature_c'),
                    'thumb'  => $covers[$step->id()] ?? null,
                ];
            }

            // A trip with no placed stops has nothing to draw.
            if ($tripSteps === []) {
                continue;
            }

            $trips[] = [
                'id'       => $trip->id(),
                'slug'     => $trip->string('slug'),
                'title'    => $trip->string('title'),
                'color'    => $trip->string('color', '#2ec27e'),
                'url'      => $trip->url(),
                'from'     => $trip->string('start_date') ?: null,
                'to'       => $trip->string('end_date') ?: null,
                'distance' => round((float) $trip->float('distance_km', 0.0), 1),
                'steps'    => $tripSteps,
            ];
        }

        return [
            'generated' => gmdate('c'),
            'bounds'    => [
                'from' => $earliest === null ? null : gmdate('c', $earliest),
                'to'   => $latest === null ? null : gmdate('c', $latest),
            ],
            'trips' => $trips,
            'stats' => [
                'trips'       => count($trips),
                'steps'       => array_sum(array_map(static fn (array $t): int => count($t['steps']), $trips)),
                'countries'   => count($countries),
                'distance_km' => round(array_sum(array_column($trips, 'distance')), 1),
            ],
        ];
    }

    /**
     * Loads every placed step for the given trips, grouped by trip.
     *
     * One query rather than one per trip: a globe with forty journeys would
     * otherwise open forty connections' worth of round trips to a database on
     * another host.
     *
     * @param list<int> $tripIds
     *
     * @return array<int,list<Step>>
     */
    private static function loadSteps(array $tripIds, ?User $user): array
    {
        $query = Step::active()
            ->whereIn('trip_id', $tripIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('trip_id')
            ->orderBy('position')
            ->orderBy('occurred_at');

        // Draft and private stops are only drawn for someone who may read them.
        if ($user === null) {
            $query->where('status', '=', Step::STATUS_PUBLISHED)
                ->where('visibility', '!=', 'private');
        } elseif (!$user->isEditor()) {
            $query->whereGroup(static function ($q) use ($user): void {
                $q->whereGroup(static function ($inner): void {
                    $inner->where('status', '=', Step::STATUS_PUBLISHED)
                        ->where('visibility', '!=', 'private');
                });

                $q->orWhere('user_id', '=', $user->id());
            });
        }

        $grouped = [];

        foreach ($query->get() as $row) {
            $step = Step::fromRow($row);
            $grouped[$step->int('trip_id')][] = $step;
        }

        return $grouped;
    }

    /**
     * One thumbnail URL per step, for the marker preview card.
     *
     * @param array<int,list<Step>> $stepsByTrip
     *
     * @return array<int,string>
     */
    private static function loadCoverThumbnails(array $stepsByTrip): array
    {
        $stepIds = [];

        foreach ($stepsByTrip as $steps) {
            foreach ($steps as $step) {
                $stepIds[] = $step->id();
            }
        }

        if ($stepIds === []) {
            return [];
        }

        // The first photo of each step. Grouping in SQL would need a window
        // function that MariaDB 10.1 lacks, so the rows come back ordered and
        // the first one per step wins.
        $rows = Database::instance()
            ->table('media')
            ->select('step_media.step_id AS step_id', 'media.uuid AS uuid', 'media.variants AS variants')
            ->join('step_media', 'step_media.media_id', '=', 'media.id')
            ->whereIn('step_media.step_id', $stepIds)
            ->where('media.kind', '=', 'image')
            ->whereNull('media.deleted_at')
            ->orderBy('step_media.step_id')
            ->orderBy('step_media.position')
            ->get();

        $thumbnails = [];

        foreach ($rows as $row) {
            $stepId = (int) $row['step_id'];

            if (isset($thumbnails[$stepId])) {
                continue;
            }

            $thumbnails[$stepId] = path('/media/thumb/' . $row['uuid']);
        }

        return $thumbnails;
    }

    /**
     * The span the time scrubber covers.
     *
     * @return array{from:?string,to:?string}
     */
    public static function timeBounds(?User $user): array
    {
        $tripIds = TripService::visibleQuery($user)->pluck('id');

        if ($tripIds === []) {
            return ['from' => null, 'to' => null];
        }

        $query = Step::active()
            ->whereIn('trip_id', array_map('intval', $tripIds))
            ->whereNotNull('occurred_at');

        $from = (clone $query)->orderBy('occurred_at')->value('occurred_at');
        $to = (clone $query)->orderBy('occurred_at', 'DESC')->value('occurred_at');

        return [
            'from' => is_string($from) ? gmdate('c', (int) strtotime($from . ' UTC')) : null,
            'to'   => is_string($to) ? gmdate('c', (int) strtotime($to . ' UTC')) : null,
        ];
    }

    /**
     * Settings the renderer reads at start-up.
     *
     * @return array<string,mixed>
     */
    public static function clientConfig(): array
    {
        return [
            'autoRotate'     => SettingsService::bool('globe.auto_rotate', true),
            'showGraticule'  => SettingsService::bool('globe.show_graticule', true),
            'showTerminator' => SettingsService::bool('globe.show_terminator', true),
            'markerScale'    => (float) SettingsService::get('globe.marker_scale', 1.0),
            'resolution'     => SettingsService::string('globe.resolution', 'low'),
            'webxr'          => (bool) \MTL\Core\Config::get('globe.webxr', true),
            'geometry'       => asset('data/land-' . (SettingsService::string('globe.resolution', 'low') === 'high' ? '50m' : '110m') . '.bin'),
        ];
    }
}
