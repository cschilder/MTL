<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Auth;

use MTL\Auth\RateLimiter;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;
use MTL\Http\Controllers\Controller;
use MTL\Services\RegistrationService;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * Asking for an account.
 *
 * Only reachable while the owner has switched registration on; the page 404s
 * otherwise rather than advertising a closed door. What is submitted here
 * becomes a *pending* account — an administrator approves it before it can
 * sign in.
 */
final class RegisterController extends Controller
{
    public function show(Request $request): Response
    {
        if (!RegistrationService::isOpen()) {
            throw HttpException::notFound();
        }

        return view('auth/register', [
            'title'   => __('auth.register'),
            'noindex' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        if (!RegistrationService::isOpen()) {
            throw HttpException::notFound();
        }

        // A handful of requests per address per hour: a registration form is
        // the internet's favourite spam target.
        $bucket = 'register:' . $request->ip();

        if (RateLimiter::tooManyAttempts($bucket, 5)) {
            return $this->back(path('/register'), __('auth.register_throttled'), 'negative');
        }

        RateLimiter::hit($bucket, 5, 3600);

        $data = Validator::forRequest($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:191',
            'password' => 'required|string|password|confirmed|raw',
        ])->validated();

        try {
            RegistrationService::register([
                'name'     => (string) $data['name'],
                'email'    => (string) $data['email'],
                'password' => (string) $data['password'],
            ]);
        } catch (\RuntimeException $e) {
            Session::flash('_old', ['name' => $data['name'], 'email' => $data['email']]);

            return $this->back(path('/register'), $e->getMessage(), 'negative');
        }

        return $this->back(path('/login'), __('auth.register_received'), 'positive');
    }
}
