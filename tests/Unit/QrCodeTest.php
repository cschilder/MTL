<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Support\QrCode;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The QR encoder used for two-factor enrolment.
 *
 * PHP has no QR decoder to round-trip against, so correctness was established
 * out of band: every code below was rendered and read back with zxing-cpp, and
 * decoded to exactly the input string. What the tests here do is keep it that
 * way — the golden hashes pin the output of those verified cases, and the
 * structural assertions catch a change that breaks the format outright.
 *
 * Note that two conforming encoders can legitimately produce different codes
 * for the same input (padding and mask selection both allow latitude), so
 * comparing against another library is not a valid test. Decoding is.
 */
final class QrCodeTest extends TestCase
{
    private const OTPAUTH = 'otpauth://totp/MTL:reiziger@mtl.r010.space'
        . '?secret=JBSWY3DPEHPK3PXP&issuer=MTL&algorithm=SHA1&digits=6&period=30';

    /**
     * @param list<list<bool>> $matrix
     */
    private function hash(array $matrix): string
    {
        $rows = array_map(
            static fn (array $row): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $row)),
            $matrix
        );

        return substr(hash('sha256', implode("\n", $rows) . "\n"), 0, 16);
    }

    // -------------------------------------------------------------------------
    // Regression: verified output must not change silently
    // -------------------------------------------------------------------------

    public function testKnownMatricesAreStable(): void
    {
        $cases = [
            ['MTL', 0, 'ba3b576605633e2b'],
            ['MTL', 3, '549478954b007cb6'],
            [self::OTPAUTH, 0, '82a65e175e843248'],
            [self::OTPAUTH, 3, 'ecbae26577824cf2'],
        ];

        foreach ($cases as [$text, $mask, $expected]) {
            $matrix = QrCode::encode($text, $mask);

            $this->assertNotNull($matrix);
            $this->assertSame($expected, $this->hash($matrix), 'matrix changed for mask ' . $mask);
        }
    }

    // -------------------------------------------------------------------------
    // Structure
    // -------------------------------------------------------------------------

    public function testVersionGrowsWithContent(): void
    {
        // Size is 17 + 4 × version, so these are versions 1, 3, 6 and 10.
        $expected = [
            1   => 21,
            36  => 29,
            100 => 41,
            200 => 57,
        ];

        foreach ($expected as $length => $size) {
            $matrix = QrCode::encode(str_repeat('a', $length));

            $this->assertNotNull($matrix, 'no matrix for ' . $length . ' bytes');
            $this->assertCount($size, $matrix, $length . ' bytes should give a ' . $size . '-module code');
            $this->assertCount($size, $matrix[0]);
        }
    }

    public function testContentBeyondVersionTenIsRefused(): void
    {
        // Version 10 at level M holds 213 bytes; anything longer has nowhere
        // to go, and the caller falls back to showing the key as text.
        $this->assertNotNull(QrCode::encode(str_repeat('a', 213)));
        $this->assertNull(QrCode::encode(str_repeat('a', 214)));
    }

    public function testFinderPatternsArePresent(): void
    {
        $matrix = QrCode::encode(self::OTPAUTH);

        $this->assertNotNull($matrix);

        $size = count($matrix);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$originX, $originY]) {
            // The middle row crosses ring, gap, 3-module core, gap, ring —
            // the 1:1:3:1:1 ratio a scanner locks onto.
            $row = '';

            for ($x = 0; $x < 7; ++$x) {
                $row .= $matrix[$originY + 3][$originX + $x] ? '1' : '0';
            }

            $this->assertSame('1011101', $row, 'finder at ' . $originX . ',' . $originY);

            // The top edge is a solid run of seven.
            $top = '';

            for ($x = 0; $x < 7; ++$x) {
                $top .= $matrix[$originY][$originX + $x] ? '1' : '0';
            }

            $this->assertSame('1111111', $top, 'finder top edge at ' . $originX . ',' . $originY);
        }
    }

    public function testSeparatorsAreLight(): void
    {
        $matrix = QrCode::encode('MTL');

        // The eighth column and row beside the top-left finder must be blank,
        // or a scanner cannot isolate the pattern.
        for ($i = 0; $i < 8; ++$i) {
            $this->assertFalse($matrix[7][$i], 'separator row at ' . $i);
            $this->assertFalse($matrix[$i][7], 'separator column at ' . $i);
        }
    }

    public function testTimingPatternsAlternate(): void
    {
        $matrix = QrCode::encode(self::OTPAUTH);

        $size = count($matrix);

        for ($i = 8; $i < $size - 8; ++$i) {
            $expected = $i % 2 === 0;

            $this->assertSame($expected, $matrix[6][$i], 'horizontal timing at ' . $i);
            $this->assertSame($expected, $matrix[$i][6], 'vertical timing at ' . $i);
        }
    }

    public function testDarkModuleIsSet(): void
    {
        $matrix = QrCode::encode('MTL');

        $size = count($matrix);

        // Always dark, whatever the content: (8, 4 × version + 9).
        $this->assertTrue($matrix[$size - 8][8]);
    }

    public function testDifferentContentProducesDifferentCodes(): void
    {
        $a = QrCode::encode('MTL one');
        $b = QrCode::encode('MTL two');

        $this->assertNotSame($this->hash($a), $this->hash($b));
    }

    public function testEveryMaskProducesAValidStructure(): void
    {
        for ($mask = 0; $mask < 8; ++$mask) {
            $matrix = QrCode::encode('MTL', $mask);

            $this->assertNotNull($matrix, 'mask ' . $mask);

            // Function patterns are never masked, so the timing row holds
            // whatever mask is chosen.
            $this->assertTrue($matrix[6][8], 'timing survived mask ' . $mask);
            $this->assertFalse($matrix[6][9], 'timing survived mask ' . $mask);
        }
    }

    // -------------------------------------------------------------------------
    // SVG output
    // -------------------------------------------------------------------------

    public function testSvgIsWellFormedAndSelfContained(): void
    {
        $svg = QrCode::svg(self::OTPAUTH);

        $this->assertContains('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertContains('role="img"', $svg);
        $this->assertContains('<path d="M', $svg);
        $this->assertContains('</svg>', $svg);

        // Nothing may be fetched: the content security policy forbids it and
        // the Android wrapper has to render this offline.
        $this->assertNotContains('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
        $this->assertNotContains('<image', $svg);
        $this->assertNotContains('<script', $svg);
    }

    public function testSvgIncludesTheQuietZone(): void
    {
        // Four modules of margin on each side, which the specification requires
        // and without which many scanners refuse to lock on.
        $svg = QrCode::svg('MTL', 4, 4);

        // 21 modules + 8 quiet = 29, times 4 pixels.
        $this->assertContains('viewBox="0 0 116 116"', $svg);
    }

    public function testOversizedContentGivesAnEmptySvg(): void
    {
        $this->assertSame('', QrCode::svg(str_repeat('a', 500)));
    }

    public function testUnicodeIsEncodedAsUtf8Bytes(): void
    {
        // Two-byte characters count as two towards the capacity, which is what
        // makes an e-mail address with an accent pick a larger version.
        $matrix = QrCode::encode('Þingvellir, Ísland');

        $this->assertNotNull($matrix);
        $this->assertCount(25, $matrix, 'twenty UTF-8 bytes needs version 2');
    }
}
