<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Middleware\SecurityHeadersMiddleware;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The Content-Security-Policy's carve-outs stay where they belong.
 *
 * The management screens may talk to Nominatim; the public site may not,
 * and nobody frames anything but this site itself. These tests pin the boundary, so an edit to the
 * policy cannot quietly widen what a public page is allowed to load.
 */
final class SecurityHeadersTest extends TestCase
{
    private function respondThrough(string $path): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'MTL test suite';
        $_POST = [];
        $_GET = [];
        $_FILES = [];

        $request = Request::capture();

        return (new SecurityHeadersMiddleware())->handle($request, static fn (): Response => new Response(''));
    }

    private function policy(Response $response): string
    {
        return (string) ($response->headers()['Content-Security-Policy'] ?? '');
    }

    public function testManagementPagesMayReachTheGeocoderAndFrameOnlyThemselves(): void
    {
        $policy = $this->policy($this->respondThrough('/admin/trips'));

        $this->assertTrue(str_contains($policy, "connect-src 'self' https://nominatim.openstreetmap.org"));
        // The report editor is this site's own StackEdit build: same origin.
        $this->assertTrue(str_contains($policy, "frame-src 'self';"));
        $this->assertFalse(str_contains($policy, 'stackedit.io'));
    }

    public function testPublicPagesStaySelfContained(): void
    {
        $policy = $this->policy($this->respondThrough('/trips/ergens-heen'));

        $this->assertTrue(str_contains($policy, "frame-src 'self'"));
        $this->assertFalse(str_contains($policy, 'stackedit.io'));
        $this->assertFalse(str_contains($policy, 'nominatim'));
    }
}
