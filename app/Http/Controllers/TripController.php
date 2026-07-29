<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Models\Album;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Services\SettingsService;
use MTL\Services\TripService;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * The public trip pages.
 */
final class TripController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $page = $this->page($request);

        $result = TripService::visibleQuery($user)
            ->orderBy('position')
            ->orderByRaw('COALESCE(`start_date`, `created_at`) DESC')
            ->paginate($page, 24);

        return view('trips/index', [
            'title'       => __('trip.trips'),
            'description' => SettingsService::string('site.tagline'),
            'canonical'   => url('/trips'),
            'wide'        => true,
            'trips'       => Trip::fromRows($result['data']),
            'pagination'  => $result,
            'statistics'  => TripService::statistics($user),
        ]);
    }

    public function show(Request $request): Response
    {
        $trip = $this->loadTrip((string) $request->param('trip', ''));

        return $this->renderTrip($trip, false);
    }

    /**
     * A trip reached through its share link.
     *
     * The token stands in for being signed in, so an unlisted trip can be sent
     * to someone without giving them an account.
     */
    public function shared(Request $request): Response
    {
        $token = (string) $request->param('token', '');

        $trip = Trip::findByShareToken($token);

        if ($trip === null || $trip->string('status') === Trip::STATUS_DRAFT) {
            throw HttpException::notFound();
        }

        return $this->renderTrip($trip, true);
    }

    private function renderTrip(Trip $trip, bool $viaShareLink): Response
    {
        $user = $this->user();

        $steps = array_values(array_filter(
            $trip->steps(),
            static fn (Step $step): bool => $viaShareLink
                ? $step->isPublished() && $step->string('visibility') !== 'private'
                : $step->isVisibleTo($user, $trip)
        ));

        $albums = Album::fromRows(
            Album::active()->where('trip_id', '=', $trip->id())->orderBy('position')->get()
        );

        $albums = array_values(array_filter(
            $albums,
            static fn (Album $album): bool => $viaShareLink || $album->isVisibleTo($user)
        ));

        $this->countView($trip);

        $cover = $trip->coverMedia();

        return view('trips/show', [
            'title'       => $trip->string('title'),
            'description' => $trip->excerpt(200),
            'canonical'   => url('/trips/' . $trip->string('slug')),
            'ogType'      => 'article',
            'ogImage'     => $cover === null ? '' : url($cover->url('large')),
            // An unlisted trip must not end up in a search engine even if the
            // link is shared publicly.
            'noindex'     => $trip->string('visibility') !== Trip::VISIBILITY_PUBLIC,
            'wide'        => true,
            'trip'        => $trip,
            'steps'       => $steps,
            'albums'      => $albums,
            'cover'       => $cover,
            'shared'      => $viaShareLink,
            'canEdit'     => $this->auth()->can('trip.update', $trip),
        ]);
    }

    /**
     * Loads a trip by slug and refuses it when the visitor may not read it.
     */
    private function loadTrip(string $slug): Trip
    {
        $trip = Trip::findBySlug($slug);

        if ($trip === null || $trip->isDeleted()) {
            throw HttpException::notFound();
        }

        if (!$trip->isVisibleTo($this->user())) {
            // 404 rather than 403 so the existence of a private trip is not
            // confirmed by its URL.
            throw HttpException::notFound();
        }

        return $trip;
    }

    /**
     * Increments the view counter, at most once per visitor per hour.
     *
     * Counting every request would make the number meaningless — a bot, a
     * refresh and a preload all look the same otherwise.
     */
    private function countView(Trip $trip): void
    {
        $key = '_viewed_trip_' . $trip->id();

        $lastSeen = \MTL\Core\Session::get($key, 0);

        if (is_int($lastSeen) && (time() - $lastSeen) < 3600) {
            return;
        }

        \MTL\Core\Session::put($key, time());

        try {
            Trip::query()->where('id', '=', $trip->id())->increment('view_count');
        } catch (\Throwable) {
            // A counter is not worth failing a page render over.
        }
    }
}
