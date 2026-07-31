<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Core\Request;
use MTL\Core\Response;

defined('MTL_APP') || exit;

/**
 * A step in the request pipeline.
 *
 * Middleware either returns a response of its own (stopping the chain) or
 * hands the request to $next and optionally adjusts what comes back.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request):Response $next
     * @param string|null                $argument the part after ':' in a route
     *                                             declaration, e.g. 'can:trip.update'
     */
    public function handle(Request $request, callable $next, ?string $argument = null): Response;
}
