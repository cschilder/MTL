<?php

declare(strict_types=1);

namespace MTL\Http\Middleware;

use MTL\Core\Config;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Session;

defined('MTL_APP') || exit;

/**
 * Applies the response headers that constrain what a page is allowed to do.
 *
 * The Content-Security-Policy is the important one: the application ships no
 * third-party scripts and inlines nothing except a per-request nonce, so the
 * policy can be strict enough to make an injected <script> inert.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private static string $nonce = '';

    /**
     * A fresh nonce per request. Templates put it on the one inline script
     * block that carries bootstrap data.
     */
    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        }

        return self::$nonce;
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Frame-Options'        => 'SAMEORIGIN',

            // The globe needs WebXR; nothing else needs a powerful feature.
            'Permissions-Policy'     => 'geolocation=(self), camera=(), microphone=(), payment=(), usb=(), xr-spatial-tracking=(self)',
        ];

        // Media responses are streamed and must not carry a document policy.
        if (!str_starts_with($request->path, '/media/')) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy();
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        // A page rendered for a signed-in visitor must never be stored by a
        // shared cache.
        if (Session::has('_auth_user')) {
            $headers['Cache-Control'] = 'private, no-store';
        }

        return $response->withHeaders($headers);
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = self::nonce();

        $directives = [
            "default-src 'self'",

            // 'strict-dynamic' lets the nonced bootstrap script load the
            // application's own modules, while still refusing anything a
            // page-injected tag tries to pull in.
            "script-src 'self' 'nonce-" . $nonce . "' 'strict-dynamic'",

            // Vanilla Framework ships plain CSS, but the globe and the editor
            // set inline styles (transform, custom properties) from script.
            "style-src 'self' 'unsafe-inline'",

            // data: covers the blurred placeholders embedded in the markup;
            // blob: covers previews of a file that is still uploading.
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",

            "font-src 'self'",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        if (!Config::isProduction()) {
            // The development server is plain HTTP; upgrading would break it.
            return implode('; ', $directives);
        }

        $directives[] = 'upgrade-insecure-requests';

        return implode('; ', $directives);
    }
}
