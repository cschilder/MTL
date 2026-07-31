<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Services\GlobeService;
use MTL\Services\SettingsService;
use MTL\Services\TripService;

defined('MTL_APP') || exit;

/**
 * The landing page: the globe, with every visible trip plotted on it.
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();

        // The globe is drawn from a JSON payload fetched separately, but the
        // trip list is rendered into the page as well: it is the fallback when
        // WebGL is unavailable, and it is what a crawler reads.
        $trips = TripService::visibleTrips($user, limit: 60);

        return view('home/index', [
            'title'       => SettingsService::string('site.title', 'MTL'),
            'description' => SettingsService::string('site.tagline'),
            'canonical'   => url('/'),
            'immersive'   => true,
            'trips'       => $trips,
            'bounds'      => GlobeService::timeBounds($user),
            'globeConfig' => GlobeService::clientConfig(),
        ]);
    }

    /**
     * The same globe on its own route, for a deep link that should not be
     * confused with the site root (the Android wrapper starts here).
     */
    public function globe(Request $request): Response
    {
        return $this->index($request);
    }

    /**
     * Served by the service worker when a navigation fails while offline.
     */
    public function offline(Request $request): Response
    {
        return view('home/offline', [
            'title'   => __('error.offline'),
            'noindex' => true,
        ]);
    }
}
