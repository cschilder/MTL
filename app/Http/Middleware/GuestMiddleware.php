<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Auth\AuthManager;
use MTL\Core\Request;
use MTL\Core\Response;

defined('MTL_APP') || exit;

/**
 * Keeps signed-in visitors away from the sign-in and registration screens.
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if (AuthManager::instance()->guest()) {
            return $next($request);
        }

        return Response::redirect(path('/admin'), 302);
    }
}
