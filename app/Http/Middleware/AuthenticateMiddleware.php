<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Auth\AuthManager;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;

defined('MTL_APP') || exit;

/**
 * Requires a signed-in account.
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if (AuthManager::instance()->check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw HttpException::unauthorized();
        }

        // Remember where they were headed so the sign-in form can send them
        // back rather than dumping them on the dashboard.
        Session::put('_intended_url', $request->fullUrl());
        Session::notify('caution', __('auth.sign_in_required'));

        return Response::redirect(path('/login'), 302);
    }
}
