<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Api;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Http\Controllers\Controller;
use MTL\Auth\RateLimiter;
use MTL\Core\Translator;
use MTL\Markdown\Markdown;
use MTL\Markdown\MarkdownOptions;
use MTL\Services\GeocodeService;

defined('MTL_APP') || exit;

/**
 * The endpoints the editor talks to.
 */
final class EditorApiController extends Controller
{
    /**
     * Renders markdown to HTML.
     *
     * This is the same renderer that produces the published page, which is the
     * point: the preview cannot disagree with the result, and the rich editing
     * surface is populated from it, so there is no second markdown
     * implementation in the browser to drift out of step.
     */
    public function preview(Request $request): Response
    {
        $this->authorize('trip.create');

        $markdown = (string) $request->input('markdown', '');

        if (strlen($markdown) > 2_000_000) {
            throw new HttpException(413, 'This document is too large to render.');
        }

        // `editing` is what fills the rich surface, `document` what fills the
        // read-only preview pane. They differ only in the heading anchors, which
        // must not be in the surface: the editor turns the surface back into
        // markdown, and an anchor there is indistinguishable from a link the
        // author typed.
        $options = match ($request->string('mode', 'document')) {
            'snippet' => MarkdownOptions::snippet(),
            'editing' => MarkdownOptions::editing(),
            default   => MarkdownOptions::document(),
        };

        try {
            $result = Markdown::renderWithContext($markdown, $options);
        } catch (\Throwable $e) {
            throw new HttpException(422, 'This text could not be rendered: ' . $e->getMessage());
        }

        return Response::json([
            'html'     => $result['html'],
            'text'     => $result['text'],
            'headings' => $result['headings'],
            'media'    => $result['media'],
            'words'    => str_word_count(strip_tags($result['text'])),
        ]);
    }

    /**
     * Keeps a draft of whatever is being typed.
     *
     * Deliberately stored in the session rather than written over the record:
     * an autosave that overwrote a published report every four seconds would
     * make "discard my changes" impossible. The draft is offered back when the
     * same form is opened again.
     */
    public function autosave(Request $request): Response
    {
        $this->requireUser();

        $key = preg_replace('/[^a-z0-9:_-]/i', '', $request->string('key'));

        if ($key === '' || $key === null) {
            throw new HttpException(422, 'A draft needs a key.');
        }

        $markdown = (string) $request->input('markdown', '');

        if (strlen($markdown) > 2_000_000) {
            throw new HttpException(413, 'This draft is too large to keep.');
        }

        $drafts = Session::get('_drafts', []);

        if (!is_array($drafts)) {
            $drafts = [];
        }

        $drafts[$key] = [
            'markdown' => $markdown,
            'at'       => time(),
        ];

        // Keep the session small: only the most recent handful of drafts.
        if (count($drafts) > 8) {
            uasort($drafts, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
            $drafts = array_slice($drafts, 0, 8, true);
        }

        Session::put('_drafts', $drafts);

        return Response::json(['ok' => true, 'at' => gmdate('c')]);
    }

    /**
     * Turns a place name into coordinates.
     *
     * Three sources, in order of intimacy: coordinates typed straight in, the
     * places this site has already used (the same town from a previous trip is
     * the common case), and finally the world at large through
     * GeocodeService — the one deliberate exception to the no-external-calls
     * rule, made server-side and only ever for a signed-in author, so no
     * visitor's browser talks to anyone but this site.
     */
    public function geocode(Request $request): Response
    {
        $this->requireUser();

        $query = $request->string('q');

        if (mb_strlen($query, 'UTF-8') < 2) {
            return Response::json(['results' => []]);
        }

        $rows = db()->table('steps')
            ->select('location_name', 'country_code', 'latitude', 'longitude')
            ->whereNotNull('latitude')
            ->whereNull('deleted_at')
            ->whereLike('location_name', $query)
            ->groupBy('location_name', 'country_code', 'latitude', 'longitude')
            ->limit(10)
            ->get();

        $results = array_map(static fn (array $row): array => [
            'name'      => (string) $row['location_name'],
            'country'   => (string) $row['country_code'],
            'latitude'  => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'source'    => 'previous_step',
        ], $rows);

        // Coordinates typed directly are accepted too: "64.14, -21.94".
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $query, $m) === 1) {
            $latitude = (float) $m[1];
            $longitude = (float) $m[2];

            if (abs($latitude) <= 90 && abs($longitude) <= 180) {
                array_unshift($results, [
                    'name'      => sprintf('%.5f, %.5f', $latitude, $longitude),
                    'country'   => '',
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                    'source'    => 'coordinates',
                ]);
            }

            return Response::json(['results' => $results]);
        }

        // The world's places, after the author's own. Rate limited per user:
        // the results are cached, but the cache must not be fillable at
        // keystroke speed against a free service.
        $bucket = 'geocode:user:' . (string) $this->auth()->id();

        if (RateLimiter::tooManyAttempts($bucket, 30)) {
            return Response::json([
                'results' => $results,
                'error'   => __('step.geocode_throttled', ['seconds' => RateLimiter::availableIn($bucket)]),
            ]);
        }

        RateLimiter::hit($bucket, 30, 60);

        foreach (GeocodeService::search($query, Translator::locale()) as $hit) {
            $results[] = [
                'name'      => $hit['name'],
                'display'   => $hit['display'],
                'country'   => $hit['country'],
                'latitude'  => $hit['latitude'],
                'longitude' => $hit['longitude'],
                'source'    => 'geocoder',
            ];
        }

        return Response::json(['results' => $results]);
    }
}
