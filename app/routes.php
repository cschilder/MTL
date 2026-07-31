<?php
/**
 * The route table.
 *
 * Returns a closure so the whole file is one expression and nothing leaks into
 * the global scope. Route names are used by route() in templates; the URLs
 * themselves appear in exactly one place, here.
 */

declare(strict_types=1);

use MTL\Core\Router;
use MTL\Http\Controllers\Admin\AlbumAdminController;
use MTL\Http\Controllers\Admin\AuditController;
use MTL\Http\Controllers\Admin\DashboardController;
use MTL\Http\Controllers\Admin\MaintenanceController;
use MTL\Http\Controllers\Admin\MediaAdminController;
use MTL\Http\Controllers\Admin\SettingsController;
use MTL\Http\Controllers\Admin\StepAdminController;
use MTL\Http\Controllers\Admin\TagAdminController;
use MTL\Http\Controllers\Admin\TripAdminController;
use MTL\Http\Controllers\Admin\UserAdminController;
use MTL\Http\Controllers\Api\EditorApiController;
use MTL\Http\Controllers\Api\GlobeApiController;
use MTL\Http\Controllers\Api\MediaApiController;
use MTL\Http\Controllers\Api\UploadApiController;
use MTL\Http\Controllers\AlbumController;
use MTL\Http\Controllers\Auth\LoginController;
use MTL\Http\Controllers\Auth\PasswordController;
use MTL\Http\Controllers\Auth\ProfileController;
use MTL\Http\Controllers\Auth\TwoFactorController;
use MTL\Http\Controllers\FeedController;
use MTL\Http\Controllers\HomeController;
use MTL\Http\Controllers\MediaController;
use MTL\Http\Controllers\SearchController;
use MTL\Http\Controllers\StepController;
use MTL\Http\Controllers\TripController;

defined('MTL_APP') || exit;

return static function (Router $router): void {

    // =========================================================================
    // Public site
    // =========================================================================

    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('/globe', [HomeController::class, 'globe'])->name('globe');

    $router->get('/trips', [TripController::class, 'index'])->name('trips.index');
    $router->get('/trips/{trip}', [TripController::class, 'show'])->name('trips.show');

    // A step lives inside its trip, which keeps the URL readable and makes the
    // trip's visibility rules apply to it automatically.
    $router->get('/trips/{trip}/{step}', [StepController::class, 'show'])->name('steps.show');

    $router->get('/albums', [AlbumController::class, 'index'])->name('albums.index');
    $router->get('/albums/{album}', [AlbumController::class, 'show'])->name('albums.show');

    $router->get('/search', [SearchController::class, 'index'])->name('search');

    // Share links for unlisted trips and albums.
    $router->get('/s/{token}', [TripController::class, 'shared'])->name('share');

    // Media delivery. The variant is part of the path so a CDN or a browser
    // cache treats each size as its own resource.
    $router->get('/media/{variant}/{uuid}', [MediaController::class, 'show'])->name('media.show');
    $router->get('/media/{variant}/{uuid}/{filename}', [MediaController::class, 'show'])->name('media.named');
    $router->get('/download/{uuid}', [MediaController::class, 'download'])->name('media.download');

    // Feeds and crawler files.
    $router->get('/feed.xml', [FeedController::class, 'rss'])->name('feed.rss');
    $router->get('/feed.json', [FeedController::class, 'json'])->name('feed.json');
    $router->get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('sitemap');
    $router->get('/robots.txt', [FeedController::class, 'robots'])->name('robots');
    $router->get('/manifest.webmanifest', [FeedController::class, 'manifest'])->name('manifest');
    $router->get('/offline', [HomeController::class, 'offline'])->name('offline');

    // Lets the Android wrapper prove it belongs to this domain, so it opens
    // full screen instead of in a Custom Tab with an address bar.
    $router->get('/.well-known/assetlinks.json', [FeedController::class, 'assetLinks'])->name('assetlinks');

    // =========================================================================
    // Read-only JSON used by the globe and by the progressive-enhancement
    // layers of the public site. Throttled because they are the cheapest
    // endpoints to hammer.
    // =========================================================================

    $router->group(['prefix' => '/api', 'middleware' => ['throttle:120,60']], static function (Router $api): void {
        $api->get('/globe', [GlobeApiController::class, 'index'])->name('api.globe');
        $api->get('/globe/trip/{trip}', [GlobeApiController::class, 'trip'])->name('api.globe.trip');
        $api->get('/search', [SearchController::class, 'api'])->name('api.search');
    });

    // =========================================================================
    // Authentication
    // =========================================================================

    $router->group(['middleware' => ['guest']], static function (Router $guest): void {
        $guest->get('/login', [LoginController::class, 'show'])->name('login');
        $guest->post('/login', [LoginController::class, 'attempt'])->name('login.attempt');

        $guest->get('/login/2fa', [TwoFactorController::class, 'challenge'])->name('login.2fa');
        $guest->post('/login/2fa', [TwoFactorController::class, 'verify'])->name('login.2fa.verify');

        $guest->get('/password/forgot', [PasswordController::class, 'request'])->name('password.request');
        $guest->post('/password/forgot', [PasswordController::class, 'sendLink'])->name('password.email');
        $guest->get('/password/reset/{token}', [PasswordController::class, 'reset'])->name('password.reset');
        $guest->post('/password/reset', [PasswordController::class, 'update'])->name('password.update');

        // Invitation acceptance: sets the first password on an invited account.
        $guest->get('/invite/{token}', [PasswordController::class, 'invite'])->name('invite.show');
        $guest->post('/invite', [PasswordController::class, 'acceptInvite'])->name('invite.accept');
    });

    $router->post('/logout', [LoginController::class, 'logout'])->name('logout');

    // =========================================================================
    // Management environment
    // =========================================================================

    $router->group(['prefix' => '/admin', 'middleware' => ['auth', 'can:admin.access']], static function (Router $admin): void {

        $admin->get('', [DashboardController::class, 'index'])->name('admin.dashboard');

        // --- Own account ------------------------------------------------------
        $admin->get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        $admin->post('/profile', [ProfileController::class, 'update'])->name('profile.update');
        $admin->post('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.password');
        $admin->post('/profile/preferences', [ProfileController::class, 'savePreferences'])->name('profile.preferences');

        $admin->get('/profile/2fa', [TwoFactorController::class, 'setup'])->name('profile.2fa');
        $admin->post('/profile/2fa', [TwoFactorController::class, 'enable'])->name('profile.2fa.enable');
        $admin->delete('/profile/2fa', [TwoFactorController::class, 'disable'])->name('profile.2fa.disable');
        $admin->post('/profile/2fa/recovery', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('profile.2fa.recovery');

        // --- Trips ------------------------------------------------------------
        $admin->get('/trips', [TripAdminController::class, 'index'])->name('admin.trips');
        $admin->get('/trips/new', [TripAdminController::class, 'create'])->name('admin.trips.create');
        $admin->post('/trips', [TripAdminController::class, 'store'])->name('admin.trips.store');
        $admin->get('/trips/{id:\d+}', [TripAdminController::class, 'edit'])->name('admin.trips.edit');
        $admin->put('/trips/{id:\d+}', [TripAdminController::class, 'update'])->name('admin.trips.update');
        $admin->delete('/trips/{id:\d+}', [TripAdminController::class, 'destroy'])->name('admin.trips.destroy');
        $admin->post('/trips/{id:\d+}/restore', [TripAdminController::class, 'restore'])->name('admin.trips.restore');
        $admin->post('/trips/{id:\d+}/reorder', [TripAdminController::class, 'reorderSteps'])->name('admin.trips.reorder');

        // --- Steps ------------------------------------------------------------
        $admin->get('/trips/{trip:\d+}/steps/new', [StepAdminController::class, 'create'])->name('admin.steps.create');
        $admin->post('/trips/{trip:\d+}/steps', [StepAdminController::class, 'store'])->name('admin.steps.store');
        $admin->get('/steps/{id:\d+}', [StepAdminController::class, 'edit'])->name('admin.steps.edit');
        $admin->put('/steps/{id:\d+}', [StepAdminController::class, 'update'])->name('admin.steps.update');
        $admin->delete('/steps/{id:\d+}', [StepAdminController::class, 'destroy'])->name('admin.steps.destroy');
        $admin->post('/steps/{id:\d+}/media', [StepAdminController::class, 'attachMedia'])->name('admin.steps.media.attach');
        $admin->delete('/steps/{id:\d+}/media/{media:\d+}', [StepAdminController::class, 'detachMedia'])->name('admin.steps.media.detach');
        $admin->post('/steps/{id:\d+}/media/order', [StepAdminController::class, 'reorderMedia'])->name('admin.steps.media.order');

        // --- Albums -----------------------------------------------------------
        $admin->get('/albums', [AlbumAdminController::class, 'index'])->name('admin.albums');
        $admin->get('/albums/new', [AlbumAdminController::class, 'create'])->name('admin.albums.create');
        $admin->post('/albums', [AlbumAdminController::class, 'store'])->name('admin.albums.store');
        $admin->get('/albums/{id:\d+}', [AlbumAdminController::class, 'edit'])->name('admin.albums.edit');
        $admin->put('/albums/{id:\d+}', [AlbumAdminController::class, 'update'])->name('admin.albums.update');
        $admin->delete('/albums/{id:\d+}', [AlbumAdminController::class, 'destroy'])->name('admin.albums.destroy');
        $admin->post('/albums/{id:\d+}/media', [AlbumAdminController::class, 'attachMedia'])->name('admin.albums.media.attach');
        $admin->delete('/albums/{id:\d+}/media/{media:\d+}', [AlbumAdminController::class, 'detachMedia'])->name('admin.albums.media.detach');
        $admin->post('/albums/{id:\d+}/media/order', [AlbumAdminController::class, 'reorderMedia'])->name('admin.albums.media.order');

        // --- Media library ----------------------------------------------------
        $admin->get('/media', [MediaAdminController::class, 'index'])->name('admin.media');
        $admin->get('/media/{id:\d+}', [MediaAdminController::class, 'edit'])->name('admin.media.edit');
        $admin->put('/media/{id:\d+}', [MediaAdminController::class, 'update'])->name('admin.media.update');
        $admin->delete('/media/{id:\d+}', [MediaAdminController::class, 'destroy'])->name('admin.media.destroy');
        $admin->post('/media/{id:\d+}/restore', [MediaAdminController::class, 'restore'])->name('admin.media.restore');
        $admin->post('/media/bulk', [MediaAdminController::class, 'bulk'])->name('admin.media.bulk');
        $admin->get('/media/trash', [MediaAdminController::class, 'trash'])->name('admin.media.trash');

        // --- Tags -------------------------------------------------------------
        $admin->get('/tags', [TagAdminController::class, 'index'])->name('admin.tags');
        $admin->post('/tags', [TagAdminController::class, 'store'])->name('admin.tags.store');
        $admin->put('/tags/{id:\d+}', [TagAdminController::class, 'update'])->name('admin.tags.update');
        $admin->delete('/tags/{id:\d+}', [TagAdminController::class, 'destroy'])->name('admin.tags.destroy');

        // --- Users ------------------------------------------------------------
        $admin->group(['middleware' => ['can:user.manage']], static function (Router $users): void {
            $users->get('/users', [UserAdminController::class, 'index'])->name('admin.users');
            $users->get('/users/new', [UserAdminController::class, 'create'])->name('admin.users.create');
            $users->post('/users', [UserAdminController::class, 'store'])->name('admin.users.store');
            $users->get('/users/{id:\d+}', [UserAdminController::class, 'edit'])->name('admin.users.edit');
            $users->put('/users/{id:\d+}', [UserAdminController::class, 'update'])->name('admin.users.update');
            $users->delete('/users/{id:\d+}', [UserAdminController::class, 'destroy'])->name('admin.users.destroy');
            $users->post('/users/{id:\d+}/restore', [UserAdminController::class, 'restore'])->name('admin.users.restore');
            $users->post('/users/{id:\d+}/invite', [UserAdminController::class, 'resendInvite'])->name('admin.users.invite');
            $users->delete('/users/{id:\d+}/sessions', [UserAdminController::class, 'revokeSessions'])->name('admin.users.sessions');
        });

        // --- Settings, audit and maintenance ----------------------------------
        $admin->group(['middleware' => ['can:settings.manage']], static function (Router $settings): void {
            $settings->get('/settings', [SettingsController::class, 'index'])->name('admin.settings');
            $settings->get('/settings/{group}', [SettingsController::class, 'index'])->name('admin.settings.group');
            $settings->put('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
        });

        $admin->group(['middleware' => ['can:audit.view']], static function (Router $audit): void {
            $audit->get('/audit', [AuditController::class, 'index'])->name('admin.audit');
            $audit->get('/audit/logs', [AuditController::class, 'logs'])->name('admin.audit.logs');
        });

        $admin->group(['middleware' => ['can:maintenance.run']], static function (Router $maintenance): void {
            $maintenance->get('/maintenance', [MaintenanceController::class, 'index'])->name('admin.maintenance');
            $maintenance->post('/maintenance/{task}', [MaintenanceController::class, 'run'])->name('admin.maintenance.run');
        });

        // --- JSON endpoints the management screens talk to --------------------
        $admin->group(['prefix' => '/api'], static function (Router $api): void {
            // Markdown preview: rendering server-side means the preview and the
            // stored page can never disagree.
            $api->post('/preview', [EditorApiController::class, 'preview'])->name('api.preview');
            $api->post('/autosave', [EditorApiController::class, 'autosave'])->name('api.autosave');
            $api->get('/geocode', [EditorApiController::class, 'geocode'])->name('api.geocode');

            $api->get('/media', [MediaApiController::class, 'index'])->name('api.media');
            $api->get('/media/{id:\d+}', [MediaApiController::class, 'show'])->name('api.media.show');

            $api->post('/upload/init', [UploadApiController::class, 'init'])->name('api.upload.init');
            $api->post('/upload/chunk', [UploadApiController::class, 'chunk'])->name('api.upload.chunk');
            $api->post('/upload/complete', [UploadApiController::class, 'complete'])->name('api.upload.complete');
            $api->get('/upload/{uuid}', [UploadApiController::class, 'status'])->name('api.upload.status');
            $api->delete('/upload/{uuid}', [UploadApiController::class, 'abort'])->name('api.upload.abort');
        });
    });
};
