<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Markdown\Markdown;
use MTL\Models\Step;
use MTL\Models\Tag;
use MTL\Models\Trip;
use MTL\Services\StepService;

defined('MTL_APP') || exit;

/**
 * A single travel report.
 */
final class StepController extends Controller
{
    public function show(Request $request): Response
    {
        $trip = Trip::findBySlug((string) $request->param('trip', ''));

        if ($trip === null || $trip->isDeleted() || !$trip->isVisibleTo($this->user())) {
            throw HttpException::notFound();
        }

        $step = Step::findBySlug($trip->id(), (string) $request->param('step', ''));

        if ($step === null || !$step->isVisibleTo($this->user(), $trip)) {
            throw HttpException::notFound();
        }

        $neighbours = StepService::neighbours($step, !$this->auth()->can('step.update', $step));

        $media = $step->media();
        $cover = $step->coverMedia();

        // The rendered HTML is stored on save; the parser only runs here if a
        // report predates that column or was written straight into the
        // database.
        $html = $step->string('body_html');

        if ($html === '' && $step->string('body_md') !== '') {
            $html = Markdown::render($step->string('body_md'));
        }

        $this->countView($step);

        return view('steps/show', [
            'title'       => $step->string('title'),
            'description' => $step->excerpt(200),
            'canonical'   => url($step->url($trip)),
            'ogType'      => 'article',
            'ogImage'     => $cover === null ? '' : url($cover->url('large')),
            'noindex'     => $trip->string('visibility') !== Trip::VISIBILITY_PUBLIC,
            'trip'        => $trip,
            'step'        => $step,
            'html'        => $html,
            'media'       => $media,
            'cover'       => $cover,
            'tags'        => Tag::forSubject('step', $step->id()),
            'previous'    => $neighbours['previous'],
            'next'        => $neighbours['next'],
            'canEdit'     => $this->auth()->can('step.update', $step),
        ]);
    }

    private function countView(Step $step): void
    {
        $key = '_viewed_step_' . $step->id();

        $lastSeen = \MTL\Core\Session::get($key, 0);

        if (is_int($lastSeen) && (time() - $lastSeen) < 3600) {
            return;
        }

        \MTL\Core\Session::put($key, time());

        try {
            Step::query()->where('id', '=', $step->id())->increment('view_count');
        } catch (\Throwable) {
            // Ignored on purpose; see TripController::countView().
        }
    }
}
