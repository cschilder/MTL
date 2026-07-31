<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Step;
use MTL\Models\Tag;
use MTL\Models\Trip;
use MTL\Services\MediaService;
use MTL\Services\StepService;

defined('MTL_APP') || exit;

/**
 * Managing the individual stops of a trip, and their galleries.
 */
final class StepAdminController extends Controller
{
    private const RULES = [
        'title'           => 'required|string|min:1|max:180',
        'slug'            => 'nullable|slug|max:180',
        'body_md'         => 'nullable|string|max:500000|raw',
        'latitude'        => 'nullable|latitude',
        'longitude'       => 'nullable|longitude',
        'altitude_m'      => 'nullable|numeric|between:-500,12000',
        'location_name'   => 'nullable|string|max:180',
        'country_code'    => 'nullable|string|max:2',
        'timezone'        => 'nullable|string|max:64',
        'occurred_at'     => 'nullable|date',
        'occurred_end_at' => 'nullable|date',
        // `sometimes` because the columns are NOT NULL; see
        // TripAdminController.
        'status'          => 'sometimes|string|in:draft,published',
        'visibility'      => 'sometimes|string|in:inherit,public,private',
        'weather'         => 'nullable|string|max:200',
        'temperature_c'   => 'nullable|numeric|between:-100,70',
        'rating'          => 'nullable|int|between:1,5',
        'cover_media_id'  => 'nullable|int',
    ];

    public function create(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('trip', '0'));

        $this->authorize('step.create', $trip);

        return view('admin/steps/edit', [
            'title'   => __('step.new'),
            'noindex' => true,
            'trip'    => $trip,
            'step'    => null,
            'media'   => [],
            'tags'    => [],
            'action'  => path('/admin/trips/' . $trip->id() . '/steps'),
        ]);
    }

    public function store(Request $request): Response
    {
        /** @var Trip $trip */
        $trip = $this->findOrFail(Trip::class, (int) $request->param('trip', '0'));

        $this->authorize('step.create', $trip);

        $data = $this->validate($request, self::RULES);

        $step = StepService::create($trip, $data, $this->requireUser());

        $this->syncTags($request, $step);
        $this->attachRequestedMedia($request, $step);

        return $this->back($step->editUrl(), __('step.created'));
    }

    public function edit(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'), true);

        $this->authorize('step.update', $step);

        $trip = $step->trip();

        return view('admin/steps/edit', [
            'title'      => $step->string('title'),
            'noindex'    => true,
            'trip'       => $trip,
            'step'       => $step,
            'media'      => $step->media(),
            'tags'       => Tag::forSubject('step', $step->id()),
            'cover'      => $step->coverMedia(),
            'suggestion' => StepService::suggestFromMedia($step),
            'action'     => path('/admin/steps/' . $step->id()),
        ]);
    }

    public function update(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'), true);

        $this->authorize('step.update', $step);

        $data = $this->validate($request, self::RULES);

        if (($data['status'] ?? '') === Step::STATUS_PUBLISHED && !$this->auth()->can('step.publish', $step)) {
            unset($data['status']);
        }

        StepService::update($step, $data);

        $this->syncTags($request, $step);

        return $this->respond($request, ['id' => $step->id()], $step->editUrl(), __('step.updated'));
    }

    public function destroy(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'), true);

        $this->authorize('step.delete', $step);

        $trip = $step->trip();

        StepService::delete($step);

        return $this->back(
            $trip === null ? path('/admin/trips') : $trip->editUrl(),
            __('step.deleted'),
            'information'
        );
    }

    // -------------------------------------------------------------------------
    // Gallery
    // -------------------------------------------------------------------------

    public function attachMedia(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'));

        $this->authorize('step.update', $step);

        $ids = array_map('intval', $request->array('media'));

        $added = MediaService::attachToStep($step, $ids);

        return $this->respond($request, ['added' => $added], $step->editUrl(), __('step.updated'));
    }

    public function detachMedia(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'));

        $this->authorize('step.update', $step);

        MediaService::detachFromStep($step, (int) $request->param('media', '0'));

        return $this->respond($request, [], $step->editUrl(), __('step.updated'));
    }

    public function reorderMedia(Request $request): Response
    {
        /** @var Step $step */
        $step = $this->findOrFail(Step::class, (int) $request->param('id', '0'));

        $this->authorize('step.update', $step);

        MediaService::reorder('step_media', 'step_id', $step, array_map('intval', $request->array('order')));

        return $this->respond($request, [], $step->editUrl(), __('step.updated'));
    }

    // -------------------------------------------------------------------------

    private function attachRequestedMedia(Request $request, Step $step): void
    {
        $ids = array_map('intval', $request->array('media'));

        if ($ids !== []) {
            MediaService::attachToStep($step, $ids);
        }
    }

    private function syncTags(Request $request, Step $step): void
    {
        if (!$request->has('tags')) {
            return;
        }

        $names = array_filter(array_map('trim', explode(',', $request->string('tags'))));

        Tag::sync('step', $step->id(), array_values($names));
    }
}
