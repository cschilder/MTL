<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Auth;

use MTL\Auth\Crypto;
use MTL\Auth\Password;
use MTL\Auth\Totp;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Http\Controllers\Controller;
use MTL\Services\AuditService;
use MTL\Services\SettingsService;
use MTL\Support\QrCode;

defined('MTL_APP') || exit;

/**
 * The optional second factor: the sign-in challenge and the enrolment screen.
 */
final class TwoFactorController extends Controller
{
    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    public function challenge(Request $request): Response
    {
        $pending = $this->auth()->awaitingTwoFactor();

        if ($pending === null) {
            return $this->back(path('/login'), __('auth.sign_in_required'), 'caution');
        }

        return view('auth/two-factor', [
            'title'   => __('auth.two_factor'),
            'noindex' => true,
            'user'    => $pending,
        ]);
    }

    public function verify(Request $request): Response
    {
        $code = $request->string('code');

        $result = $this->auth()->completeTwoFactor($code, $request);

        if (!$result->succeeded()) {
            return $this->back(path('/login/2fa'), __('auth.two_factor_invalid'), 'negative');
        }

        $intended = Session::pull('_intended_url');

        return $this->back(
            is_string($intended) && $intended !== '' ? $intended : path('/admin'),
            $result->message()
        );
    }

    // -------------------------------------------------------------------------
    // Enrolment
    // -------------------------------------------------------------------------

    public function setup(Request $request): Response
    {
        $user = $this->requireUser();

        if ($user->hasTwoFactor()) {
            return view('auth/two-factor-manage', [
                'title'   => __('auth.two_factor'),
                'noindex' => true,
                'user'    => $user,
                'remaining' => count($user->json('recovery_codes')),
            ]);
        }

        // A secret is generated once per enrolment attempt and parked in the
        // session: writing it to the account before a code has been verified
        // would lock the owner out if they never finished.
        $secret = Session::get('_2fa_secret');

        if (!is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            Session::put('_2fa_secret', $secret);
        }

        $uri = Totp::provisioningUri(
            $secret,
            $user->string('email'),
            SettingsService::string('site.title', 'MTL')
        );

        return view('auth/two-factor-setup', [
            'title'   => __('auth.two_factor'),
            'noindex' => true,
            'secret'  => $secret,
            'uri'     => $uri,
            'qr'      => QrCode::svg($uri),
        ]);
    }

    public function enable(Request $request): Response
    {
        $user = $this->requireUser();

        $secret = Session::get('_2fa_secret');

        if (!is_string($secret) || $secret === '') {
            return $this->back(path('/admin/profile/2fa'), __('auth.two_factor_invalid'), 'negative');
        }

        $step = Totp::verify($secret, $request->string('code'));

        if ($step === null) {
            return $this->back(path('/admin/profile/2fa'), __('auth.two_factor_invalid'), 'negative');
        }

        $codes = Totp::generateRecoveryCodes();

        $user->update([
            'totp_secret'       => Crypto::encrypt($secret),
            'totp_confirmed_at' => gmdate('Y-m-d H:i:s'),
            // Only the hashes are stored; the plaintext is shown once, now.
            'recovery_codes'    => array_map(
                static fn (string $code): string => hash('sha256', str_replace('-', '', $code)),
                $codes
            ),
        ]);

        $user->setPreference('totp.last_step', $step);

        Session::forget('_2fa_secret');

        AuditService::log('user.two_factor_enabled', $user);

        // Flashed rather than rendered directly, because this is a redirect
        // and the codes must survive exactly one more request.
        Session::flash('_recovery_codes', $codes);

        return $this->back(path('/admin/profile/2fa'), __('auth.two_factor_enabled'));
    }

    public function disable(Request $request): Response
    {
        $user = $this->requireUser();

        // Turning off a second factor is a privileged act: the password proves
        // it is the account holder and not someone at a borrowed screen.
        if (!Password::verify($request->string('password'), $user->string('password_hash'))) {
            return $this->back(path('/admin/profile/2fa'), __('auth.password_wrong'), 'negative');
        }

        if (SettingsService::bool('security.force_2fa_for_admins') && $user->isAdmin()) {
            throw HttpException::forbidden('Two-step verification is required for administrators.');
        }

        $user->update([
            'totp_secret'       => null,
            'totp_confirmed_at' => null,
            'recovery_codes'    => null,
        ]);

        AuditService::log('user.two_factor_disabled', $user);

        return $this->back(path('/admin/profile/2fa'), __('auth.two_factor_disabled'), 'caution');
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->requireUser();

        if (!$user->hasTwoFactor()) {
            throw HttpException::forbidden();
        }

        if (!Password::verify($request->string('password'), $user->string('password_hash'))) {
            return $this->back(path('/admin/profile/2fa'), __('auth.password_wrong'), 'negative');
        }

        $codes = Totp::generateRecoveryCodes();

        $user->update([
            'recovery_codes' => array_map(
                static fn (string $code): string => hash('sha256', str_replace('-', '', $code)),
                $codes
            ),
        ]);

        AuditService::log('user.recovery_codes_regenerated', $user);

        Session::flash('_recovery_codes', $codes);

        return $this->back(path('/admin/profile/2fa'), __('auth.recovery_codes'));
    }
}
