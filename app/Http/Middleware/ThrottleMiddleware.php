<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Auth\AuthManager;
use MTL\Auth\RateLimiter;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;

defined('MTL_APP') || exit;

/**
 * Generic rate limiting, declared on a route as `throttle:30,60` — thirty
 * requests per sixty seconds.
 *
 * Counted per signed-in account where there is one, per IP address otherwise,
 * so one visitor on a shared connection cannot exhaust everybody's budget.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        [$limit, $window] = array_pad(explode(',', $argument ?? '60,60'), 2, '60');

        $limit = max(1, (int) $limit);
        $window = max(1, (int) $window);

        $identity = AuthManager::instance()->id();
        $bucket = sprintf(
            'route:%s:%s',
            $request->path,
            $identity !== null ? 'u' . $identity : 'ip' . $request->ip()
        );

        $allowed = RateLimiter::hit($bucket, $limit, $window);
        $used = RateLimiter::attempts($bucket);

        if (!$allowed) {
            $retryAfter = RateLimiter::availableIn($bucket);

            throw HttpException::tooManyRequests(
                __('error.too_many_requests'),
                max(1, $retryAfter)
            );
        }

        return $next($request)->withHeaders([
            'X-RateLimit-Limit'     => (string) $limit,
            'X-RateLimit-Remaining' => (string) max(0, $limit - $used),
        ]);
    }
}
