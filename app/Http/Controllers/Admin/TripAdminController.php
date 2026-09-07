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
use MTL\Models\User;
use MTL\Services\CollaborationService;
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
            // Their own journeys, plus every journey they are linked to as a
            // travel companion.
            $shared = CollaborationService::tripIdsFor($user);

            $query->whereGroup(static function (QueryBuilder $q) use ($user, $shared): void {
                $q->where('user_id', '=', $user->id());
                $q->whereIn('id', $shared, 'OR');
            });
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

        $canShare = $this->auth()->can('trip.share', $trip);

        return view('admin/trips/edit', [
            'title'         => $trip->string('title'),
            'noindex'       => true,
            'trip'          => $trip,
            'steps'         => $trip->steps(),
            'albums'        => Album::fromRows(
                Album::active()->where('trip_id', '=', $trip->id())->orderBy('position')->get()
            ),
            'tags'          => Tag::forSubject('trip', $trip->id()),
            'cover'         => $trip->coverMedia(),
            'action'        => path('/admin/trips/' . $trip->id()),
            'collaborators' => CollaborationService::usersFor($trip),
            'canShare'      => $canShare,
            'candidates'    => $canShare ? $this->collaboratorCandidates($trip) : [],
        ]);
    }

    /**
     * The companion links live on the trip's edit page; anyone who lands on
     * the collaborators URL itself (typed, bookmarked, a back button) is sent
     * there instead of getting a 404 for a page that never existed.
     */
    public function collaborators(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'), true);

        $this->authorize('trip.update', $trip);

        return Response::redirect($trip->editUrl() . '#reisgenoten', 303);
    }

    /**
     * Links a registered member to the trip as a travel companion.
     */
    public function addCollaborator(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'));

        $this->authorize('trip.share', $trip);

        // A missing or unknown member is a form mishap, not a missing page:
        // answer on the trip itself rather than with a bare 404.
        $member = User::find((int) $request->input('user_id', 0));

        if ($member === null) {
            return $this->back($trip->editUrl() . '#reisgenoten', __('trip.collaborator_unknown'), 'negative');
        }

        if (!$member->isActive()) {
            return $this->back($trip->editUrl(), __('trip.collaborator_inactive'), 'negative');
        }

        CollaborationService::add($trip, $member, $this->requireUser());

        return $this->back($trip->editUrl(), __('trip.collaborator_added', ['name' => $member->string('name')]));
    }

    public function removeCollaborator(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'));

        $this->authorize('trip.share', $trip);

        /** @var User $member */
        $member = $this->findOrFail(User::class, (int) $request->param('user', '0'));

        CollaborationService::remove($trip, $member, $this->requireUser());

        return $this->back($trip->editUrl(), __('trip.collaborator_removed', ['name' => $member->string('name')]));
    }

    /**
     * Active members who could still be linked: everyone except the owner and
     * those already linked.
     *
     * @return list<User>
     */
    private function collaboratorCandidates(Trip $trip): array
    {
        $taken = array_map(static fn (User $u): int => $u->id(), CollaborationService::usersFor($trip));
        $taken[] = $trip->int('user_id');

        $users = User::fromRows(
            User::query()->where('status', '=', 'active')->orderBy('name')->get()
        );

        return array_values(array_filter(
            $users,
            static fn (User $u): bool => !in_array($u->id(), $taken, true)
        ));
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
     * Publishes every draft stop of the trip in one go.
     *
     * The stop form defaults to draft, and a published trip full of draft
     * stops is invisible on a visitor's globe — the quiet second half of "my
     * published trip is not there". One button beats editing every stop.
     */
    public function publishSteps(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('id', '0'));

        $this->authorize('trip.update', $trip);

        $published = 0;

        foreach ($trip->steps() as $step) {
            if ($step->string('status') === 'published' || !$this->auth()->can('step.publish', $step)) {
                continue;
            }

            StepService::update($step, ['status' => 'published']);
            $published++;
        }

        return $this->back($trip->editUrl(), __('trip.steps_published', ['count' => $published]));
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
