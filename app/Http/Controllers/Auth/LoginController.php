<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Auth;

use MTL\Auth\AttemptStatus;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Http\Controllers\Controller;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * Signing in and out.
 */
final class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        return view('auth/login', [
            'title'   => __('auth.sign_in'),
            'noindex' => true,
        ]);
    }

    public function attempt(Request $request): Response
    {
        $data = Validator::forRequest($request, [
            'email'    => 'required|email',
            'password' => 'required|string|max:200|raw',
            'remember' => 'nullable|bool',
        ])->validated();

        $result = $this->auth()->attempt(
            (string) $data['email'],
            (string) $data['password'],
            (bool) ($data['remember'] ?? false),
            $request
        );

        if ($result->status === AttemptStatus::TwoFactorRequired) {
            return $this->back(path('/login/2fa'), $result->message(), 'information');
        }

        if ($result->status === AttemptStatus::NotActivated) {
            return $this->back(path('/login'), $result->message(), 'caution');
        }

        if (!$result->succeeded()) {
            // The address is flashed back so it does not have to be retyped;
            // the password never is.
            Session::flash('_old', ['email' => $data['email']]);

            $status = $result->status === AttemptStatus::Throttled ? 429 : 401;

            if ($request->wantsJson()) {
                return Response::json(['error' => $result->message()], $status);
            }

            return $this->back(path('/login'), $result->message(), 'negative');
        }

        return $this->redirectAfterLogin($request, $result->message());
    }

    public function logout(Request $request): Response
    {
        $this->auth()->logout();

        return $this->back(path('/'), __('auth.signed_out'), 'information');
    }

    /**
     * Sends the visitor where they were heading before being asked to sign in.
     */
    private function redirectAfterLogin(Request $request, string $message): Response
    {
        $intended = Session::pull('_intended_url');

        $target = is_string($intended) && $intended !== '' ? $intended : path('/admin');

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'redirect' => $target]);
        }

        return $this->back($target, $message);
    }
}
