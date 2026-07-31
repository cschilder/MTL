<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Media;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Services\AuditService;
use MTL\Services\MaintenanceService;
use MTL\Services\TripService;

defined('MTL_APP') || exit;

/**
 * The management landing page.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        // An author sees their own figures; anyone who can edit everything
        // sees the whole site.
        $scoped = !$user->isEditor();

        $trips = Trip::active();
        $steps = Step::active();
        $media = Media::active();

        if ($scoped) {
            $trips->where('user_id', '=', $user->id());
            $steps->where('user_id', '=', $user->id());
            $media->where('user_id', '=', $user->id());
        }

        return view('admin/dashboard', [
            'title'    => __('admin.dashboard'),
            'noindex'  => true,
            'counts'   => [
                'trips'  => (clone $trips)->count(),
                'steps'  => (clone $steps)->count(),
                'media'  => (clone $media)->count(),
                'drafts' => (clone $trips)->where('status', '=', Trip::STATUS_DRAFT)->count(),
            ],
            'statistics' => TripService::statistics($user),
            'recentTrips' => Trip::fromRows(
                (clone $trips)->latest('updated_at')->limit(6)->get()
            ),
            'recentSteps' => Step::fromRows(
                (clone $steps)->latest('updated_at')->limit(8)->get()
            ),
            'recentMedia' => Media::fromRows(
                (clone $media)->where('kind', '=', 'image')->latest('created_at')->limit(12)->get()
            ),
            // The activity feed and the system panel are only meaningful to
            // someone who can act on them.
            'activity' => $user->isEditor()
                ? AuditService::query()->limit(12)->get()
                : [],
            'health'   => $this->auth()->can('maintenance.run') ? MaintenanceService::healthReport() : [],
        ]);
    }
}
