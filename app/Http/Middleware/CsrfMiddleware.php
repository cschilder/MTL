<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Core\Csrf;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;

defined('MTL_APP') || exit;

/**
 * Rejects state-changing requests that do not carry a valid CSRF token.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Requests that only read never change state, so they are exempt.
     *
     * @var list<string>
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if (Csrf::check($request)) {
            return $next($request);
        }

        // 419 rather than 403: the usual cause is a form left open until the
        // session expired, and the error page says so.
        throw new HttpException(419, __('error.session_expired'));
    }
}
