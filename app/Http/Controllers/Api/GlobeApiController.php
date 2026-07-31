<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Api;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Trip;
use MTL\Services\GlobeService;

defined('MTL_APP') || exit;

/**
 * The JSON the globe renders from.
 */
final class GlobeApiController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();

        $payload = GlobeService::payload($user);

        return Response::json($payload)->withHeaders($this->cacheHeaders($user));
    }

    /**
     * One trip's stops, for the globe embedded on a trip page.
     */
    public function trip(Request $request): Response
    {
        $slug = $request->param('trip', '');

        $trip = Trip::findBySlug((string) $slug);

        if ($trip === null || !$trip->isVisibleTo($this->user())) {
            throw HttpException::notFound();
        }

        return Response::json(GlobeService::payload($this->user(), $trip->id()))
            ->withHeaders($this->cacheHeaders($this->user()));
    }

    /**
     * @return array<string,string>
     */
    private function cacheHeaders(?\MTL\Models\User $user): array
    {
        // A signed-in visitor sees their own drafts, so their copy must not be
        // stored by a shared cache. Anonymous responses are identical for
        // everyone and can be held briefly.
        return $user === null
            ? ['Cache-Control' => 'public, max-age=300']
            : ['Cache-Control' => 'private, no-store'];
    }
}
