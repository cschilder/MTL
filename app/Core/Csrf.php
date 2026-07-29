<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Cross-site request forgery protection.
 *
 * One token per session, compared in constant time. The token is exposed to
 * JavaScript through a meta tag so fetch() calls can send it in a header
 * instead of a form field.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }

        return $token;
    }

    public static function check(Request $request): bool
    {
        $expected = Session::get(self::KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->header('x-csrf-token')
            ?? $request->body['_token']
            ?? $request->json()['_token']
            ?? '';

        if (!is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Rotates the token. Called on login and logout so a token captured before
     * a privilege change cannot be replayed after it.
     */
    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::put(self::KEY, $token);

        return $token;
    }
}
