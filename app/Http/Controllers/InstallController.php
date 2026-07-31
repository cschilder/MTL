<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Auth\Password;
use MTL\Core\Config;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Models\User;
use MTL\Services\AuditService;
use MTL\Services\InstallService;
use MTL\Services\SettingsService;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * The web installer.
 *
 * Three steps, in the order a fresh upload needs them: the environment, the
 * database, the first administrator. Each step is derived from the actual state
 * of the system rather than from a stored wizard position, so refreshing,
 * going back, or a failure halfway never wedges the flow — the installer
 * simply looks again and shows whatever is still missing.
 *
 * The whole controller only exists between "config/config.php is filled in"
 * and "the first account exists". Before that, Application shows the static
 * setup notice; after it, every route here is a 404.
 */
final class InstallController extends Controller
{
    /** Step 1: the environment, after applying every repair it can. */
    public function environment(Request $request): Response
    {
        $this->refuseWhenInstalled();

        // Fix first, then report: the page shows the state *after* the
        // installer has done everything it can do by itself.
        $repairs = InstallService::repair();

        $checks = InstallService::checks();
        $ready = InstallService::required($checks);
        $database = $ready ? InstallService::databaseStatus() : null;

        return view('install/environment', [
            'title'    => __('install.title'),
            'step'     => 'environment',
            'repairs'  => $repairs,
            'checks'   => $checks,
            'ready'    => $ready,
            'database' => $database,
        ]);
    }

    /** Step 2: create or update the schema, then move on. */
    public function migrate(Request $request): Response
    {
        $this->refuseWhenInstalled();

        if (!InstallService::required(InstallService::checks())) {
            return $this->redirect(path('/install'));
        }

        try {
            $ran = InstallService::migrate();
        } catch (\Throwable $e) {
            Session::flash('error', __('install.migrate_failed', ['reason' => $e->getMessage()]));

            return $this->redirect(path('/install'));
        }

        Session::flash('migrated', count($ran));

        return $this->redirect(path('/install/admin'));
    }

    /** Step 3: the first administrator. */
    public function administrator(Request $request): Response
    {
        $this->refuseWhenInstalled();

        // The form needs the schema; without it, back to the overview.
        if (!InstallService::databaseStatus()['ready'] || InstallService::databaseStatus()['pending'] > 0) {
            return $this->redirect(path('/install'));
        }

        return view('install/administrator', [
            'title'    => __('install.title'),
            'step'     => 'administrator',
            'migrated' => (int) Session::flashed('migrated', -1),
            'siteHost' => (string) (parse_url((string) Config::get('app.url', ''), PHP_URL_HOST) ?: 'MTL'),
        ]);
    }

    public function createAdministrator(Request $request): Response
    {
        $this->refuseWhenInstalled();

        if (!InstallService::databaseStatus()['ready']) {
            return $this->redirect(path('/install'));
        }

        $clean = Validator::forRequest($request, [
            'site_title' => 'required|string|max:120',
            'name'       => 'required|string|max:120',
            'email'      => 'required|email',
            'password'   => 'required|password|confirmed',
        ])->labels([
            'site_title' => __('install.site_title'),
            'name'       => __('install.admin_name'),
            'email'      => __('install.admin_email'),
            'password'   => __('install.admin_password'),
        ])->validated();

        // A race between two browser tabs must not create two first accounts.
        if (!User::noneExist()) {
            InstallService::writeLock();

            throw new HttpException(404);
        }

        $user = User::create([
            'name'              => $clean['name'],
            'email'             => $clean['email'],
            'password_hash'     => Password::hash((string) $request->input('password')),
            'role'              => User::ROLE_ADMIN,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        SettingsService::set('site.title', (string) $clean['site_title']);

        AuditService::log('user.created', $user, ['role' => User::ROLE_ADMIN, 'via' => 'installer'], $user);

        InstallService::writeLock();

        // Signed in right away: the next thing anyone does after installing is
        // look at the empty site they just made, not type the password again.
        $this->auth()->login($user, false, $request);

        Session::put('install.completed', true);

        return $this->redirect(path('/install/done'));
    }

    /** Step 4: done. Only shown to the session that finished the wizard. */
    public function done(Request $request): Response
    {
        if (!InstallService::isInstalled() || Session::get('install.completed') !== true) {
            throw new HttpException(404);
        }

        return view('install/done', [
            'title' => __('install.title'),
            'step'  => 'done',
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Once an account exists the installer is over, permanently. 404 rather
     * than a redirect: the route should be indistinguishable from one that
     * never existed.
     */
    private function refuseWhenInstalled(): void
    {
        if (InstallService::isInstalled()) {
            throw new HttpException(404);
        }
    }

    private function redirect(string $to): Response
    {
        return (new Response('', 303))->header('Location', $to);
    }
}
