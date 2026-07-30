<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Markdown\Url;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The URL allow-list applied to links and images in user-written markdown.
 *
 * This is the boundary that stops a travel report from carrying a script. It is
 * an allow-list of schemes rather than a block-list, and the cases below are the
 * ones that defeat a block-list or a naive `str_starts_with('javascript:')`
 * check: control characters browsers strip before reading the scheme, HTML
 * entities they decode first, mixed case, and colons that belong to a path
 * rather than to a scheme.
 */
final class UrlTest extends TestCase
{
    public function testOrdinaryWebUrlsAreAllowed(): void
    {
        foreach ([
            'https://example.com',
            'http://example.com/path?a=1#b',
            'https://example.com:8443/x',
            'HTTPS://EXAMPLE.COM',
            'mailto:reiziger@example.com',
            'tel:+31612345678',
            'ftp://files.example.com/x.zip',
            'geo:63.985,-22.605',
        ] as $url) {
            $this->assertTrue(Url::isSafe($url), 'should be allowed: ' . $url);
        }
    }

    public function testRelativeUrlsAreAllowed(): void
    {
        foreach ([
            '/trips/ijsland',
            '#een-kop',
            '?page=2',
            'photos/keflavik.jpg',
            './x.jpg',
            '../x.jpg',
            'mtl:media/3cf29681-9b1d-4874-8678-97d9024e53f7',
        ] as $url) {
            $this->assertTrue(Url::isSafe($url), 'should be allowed: ' . $url);
        }
    }

    public function testScriptSchemesAreRefused(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            'JAVASCRIPT:alert(1)',
            'vbscript:msgbox(1)',
            'data:text/html,<script>alert(1)</script>',
            'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pjwvc2NyaXB0Pjwvc3ZnPg==',
            'file:///etc/passwd',
            'jar:http://example.com!/',
            'blob:https://example.com/uuid',
            'about:blank',
            'chrome://settings',
            'view-source:https://example.com',
        ] as $url) {
            $this->assertFalse(Url::isSafe($url), 'should be refused: ' . $url);
        }
    }

    /**
     * A browser removes control characters before it reads the scheme, so
     * "java\tscript:" and "java\nscript:" both run. Anything that only compares
     * the literal string is defeated by this.
     */
    public function testControlCharactersCannotHideAScriptScheme(): void
    {
        foreach ([
            "java\tscript:alert(1)",
            "java\nscript:alert(1)",
            "java\rscript:alert(1)",
            "java\0script:alert(1)",
            "\tjavascript:alert(1)",
            " javascript:alert(1)",
            "jav\x01ascript:alert(1)",
            "java\x7Fscript:alert(1)",
        ] as $url) {
            $this->assertFalse(Url::isSafe($url), 'should be refused: ' . json_encode($url));
        }
    }

    /**
     * A colon inside a path is not a scheme. Refusing these would break ordinary
     * links, which is how allow-lists end up being switched off.
     */
    public function testAColonInThePathIsNotAScheme(): void
    {
        foreach ([
            '/notes/12:30-vertrek',
            'photos/12:30.jpg',
            '?time=12:30',
            '#12:30',
        ] as $url) {
            $this->assertTrue(Url::isSafe($url), 'should be allowed: ' . $url);
        }
    }

    public function testEmptyAndWhitespaceOnlyUrlsAreRefused(): void
    {
        $this->assertFalse(Url::isSafe(''));
        $this->assertFalse(Url::isSafe('   '));
        $this->assertFalse(Url::isSafe("\t\n"));
    }

    /**
     * A protocol-relative URL inherits the page's scheme, which is https here,
     * so it cannot be used to downgrade or to run a script.
     */
    public function testProtocolRelativeUrlsAreAllowed(): void
    {
        $this->assertTrue(Url::isSafe('//example.com/x.jpg'));
    }

    public function testNormaliseStripsCharactersThatConfuseAParser(): void
    {
        $this->assertSame('https://example.com/x', Url::normalise("https://example.com/x\n"));
        $this->assertSame('https://example.com/x', Url::normalise("https://example.com/\x00x"));
        $this->assertSame('https://example.com/x', Url::normalise("  https://example.com/x  "));
    }

    public function testExternalDetection(): void
    {
        $this->assertFalse(Url::isExternal('/trips/ijsland'));
        $this->assertFalse(Url::isExternal('#een-kop'));
        $this->assertFalse(Url::isExternal('?page=2'));
        $this->assertTrue(Url::isExternal('https://example.com'));
        $this->assertTrue(Url::isExternal('mailto:reiziger@example.com'));
    }
}
