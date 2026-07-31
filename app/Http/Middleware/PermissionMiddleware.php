<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Auth\AuthManager;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;

defined('MTL_APP') || exit;

/**
 * Requires a permission, declared on the route as `can:trip.update`.
 *
 * This is the coarse check that protects a whole route. Anything that depends
 * on the specific record — "may this author edit *this* trip?" — is checked in
 * the controller, where the record has been loaded.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if ($argument === null || $argument === '') {
            throw new \LogicException('The can: middleware needs a permission, e.g. can:trip.update');
        }

        $auth = AuthManager::instance();

        if ($auth->guest()) {
            return (new AuthenticateMiddleware())->handle($request, $next);
        }

        // Several permissions separated by a comma pass when any one holds,
        // which is what a screen reachable by more than one role needs.
        foreach (explode(',', $argument) as $permission) {
            if ($auth->can(trim($permission))) {
                return $next($request);
            }
        }

        throw HttpException::forbidden();
    }
}
