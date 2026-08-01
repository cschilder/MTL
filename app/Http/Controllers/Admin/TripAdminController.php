<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Album;
use MTL\Models\Tag;
use MTL\Models\Trip;
use MTL\Services\StepService;
use MTL\Services\TripService;

defined('MTL_APP') || exit;

/**
 * Managing trips.
 */
final class TripAdminController extends Controller
{
    /** Validation rules shared by create and update. */
    private const RULES = [
        'title'          => 'required|string|min:2|max:180',
        'slug'           => 'nullable|slug|max:180',
        'summary'        => 'nullable|string|max:500',
        'body_md'        => 'nullable|string|max:200000|raw',
        'start_date'     => 'nullable|date',
        'end_date'       => 'nullable|date',
        // `sometimes`, not `nullable`, for everything that lives in a NOT
        // NULL column. `nullable` materialises an absent field as null, and
        // the update then wrote that null into the column: every trip save
        // failed on a strict-mode server because the form posts no
        // `position`. `sometimes` keeps an absent field absent.
        'status'         => 'sometimes|string|in:draft,published,archived',
        'visibility'     => 'sometimes|string|in:public,unlisted,private',
        'color'          => 'sometimes|string|max:7',
        'cover_media_id' => 'nullable|int',
        'position'       => 'sometimes|int',
    ];

    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        $query = Trip::query()->latest('updated_at');

        if (!$user->isEditor()) {
            $query->where('user_id', '=', $user->id());
        }

        // The bin is a separate view, not a filter that quietly includes
        // deleted rows in the normal list.
        if ($request->string('trashed') === '1') {
            $query->whereNotNull('deleted_at');
        } else {
            $query->whereNull('deleted_at');
        }

        $search = $request->string('q');

        if ($search !== '') {
            $query->whereGroup(static function (QueryBuilder $q) use ($search): void {
                $q->whereLike('title', $search)->whereLike('summary', $search, 'OR');
            });
        }

        $status = $request->string('status');

        if (in_array($status, ['draft', 'published', 'archived'], true)) {
            $query->where('status', '=', $status);
        }

        $result = $query->paginate($this->page($request), 25);

        return view('admin/trips/index', [
            'title'      => __('trip.trips'),
            'noindex'    => true,
            'trips'      => Trip::fromRows($result['data']),
            'pagination' => $result,
            'filters'    => ['q' => $search, 'status' => $status, 'trashed' => $request->string('trashed')],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('trip.create');

        return view('admin/trips/edit', [
            'title'   => __('trip.new'),
            'noindex' => true,
            'trip'    => null,
            'tags'    => [],
            'albums'  => [],
            'action'  => path('/admin/trips'),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('trip.create');

        $data = $this->validate($request, self::RULES);

        $trip = TripService::create($data, $this->requireUser());

        $this->syncTags($request, $trip);

        return $this->back($trip->editUrl(), __('trip.created'));
    }

    public function edit(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'), true);

        $this->authorize('trip.update', $trip);

        return view('admin/trips/edit', [
            'title'   => $trip->string('title'),
            'noindex' => true,
            'trip'    => $trip,
            'steps'   => $trip->steps(),
            'albums'  => Album::fromRows(
                Album::active()->where('trip_id', '=', $trip->id())->orderBy('position')->get()
            ),
            'tags'    => Tag::forSubject('trip', $trip->id()),
            'cover'   => $trip->coverMedia(),
            'action'  => path('/admin/trips/' . $trip->id()),
        ]);
    }

    public function update(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'), true);

        $this->authorize('trip.update', $trip);

        $data = $this->validate($request, self::RULES);

        // Publishing is a separate permission from editing, so an author who
        // may only draft cannot push a trip live.
        if (($data['status'] ?? '') === Trip::STATUS_PUBLISHED && !$this->auth()->can('trip.publish', $trip)) {
            unset($data['status']);
        }

        TripService::update($trip, $data);

        $this->syncTags($request, $trip);

        return $this->respond($request, ['id' => $trip->id()], $trip->editUrl(), __('trip.updated'));
    }

    public function destroy(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'), true);

        $this->authorize('trip.delete', $trip);

        TripService::delete($trip);

        return $this->back(path('/admin/trips'), __('trip.deleted'), 'information');
    }

    public function restore(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'), true);

        $this->authorize('trip.update', $trip);

        TripService::restore($trip);

        return $this->back($trip->editUrl(), __('trip.restored'));
    }

    /**
     * Applies a new order to the trip's steps.
     */
    public function reorderSteps(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'));

        $this->authorize('trip.update', $trip);

        $order = array_map('intval', $request->array('order'));

        TripService::reorderSteps($trip, $order);

        return $this->respond($request, ['order' => $order], $trip->editUrl(), __('trip.updated'));
    }

    /**
     * Places every stop of the trip that has no coordinates yet.
     *
     * One button for "I typed my whole journey and the globe is still empty":
     * each unplaced stop is given its position from the geotag of its photos,
     * its location name, or its title, in that order.
     */
    public function geocodeSteps(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'));

        $this->authorize('trip.update', $trip);

        // Lookups against the outside world take a second each, deliberately;
        // a long trip must not die on the default execution limit.
        @set_time_limit(300);

        $placed = 0;
        $left = 0;

        foreach ($trip->steps() as $step) {
            if ($step->hasCoordinates()) {
                continue;
            }

            StepService::place($step) ? $placed++ : $left++;
        }

        $message = __('trip.geocoded', ['placed' => $placed, 'left' => $left]);

        return $this->back($trip->editUrl(), $message, $placed === 0 && $left > 0 ? 'caution' : 'positive');
    }

    /**
     * Tags arrive as a comma-separated field.
     */
    private function syncTags(Request $request, Trip $trip): void
    {
        if (!$request->has('tags') && $request->string('tags') === '') {
            return;
        }

        $names = array_filter(array_map('trim', explode(',', $request->string('tags'))));

        Tag::sync('trip', $trip->id(), array_values($names));
    }
}
