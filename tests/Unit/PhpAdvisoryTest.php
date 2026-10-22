<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Support\PhpAdvisory;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The PHP advisory check.
 *
 * Born from a support question — "is the PHP we run on vulnerable?" — that
 * the application could not answer about itself. Now it can, and this pins
 * the arithmetic: fixed releases pass, older ones in a supported branch name
 * the release to reach, end-of-life branches say there is none, and anything
 * the table does not know stays quiet.
 */
final class PhpAdvisoryTest extends TestCase
{
    public function testAFixedReleaseIsSafe(): void
    {
        $this->assertNull(PhpAdvisory::check('8.3.36'));
        $this->assertNull(PhpAdvisory::check('8.3.40'));
        $this->assertNull(PhpAdvisory::check('8.2.35'));
        $this->assertNull(PhpAdvisory::check('8.4.27'));
        $this->assertNull(PhpAdvisory::check('8.5.12'));

        // Distribution suffixes and release candidates: the numeric core counts.
        $this->assertNull(PhpAdvisory::check('8.3.36-1ubuntu1'));
        $this->assertNull(PhpAdvisory::check('8.4.27RC1'));
    }

    public function testAnOlderReleaseInASupportedBranchNamesTheFix(): void
    {
        $report = PhpAdvisory::check('8.3.35');

        $this->assertNotNull($report);
        $this->assertSame('8.3', $report['branch']);
        $this->assertSame('8.3.36', $report['fixed']);
        $this->assertContains('CVE-2026-109618', $report['advisory']);

        $this->assertSame('8.4.27', PhpAdvisory::check('8.4.0')['fixed']);
        $this->assertSame('8.2.35', PhpAdvisory::check('8.2.34')['fixed']);
    }

    public function testAnEndOfLifeBranchHasNoFixToName(): void
    {
        $report = PhpAdvisory::check('8.1.31');

        $this->assertNotNull($report);
        $this->assertSame('8.1', $report['branch']);
        $this->assertNull($report['fixed']);

        $this->assertNull(PhpAdvisory::check('7.4.33')['fixed']);
    }

    public function testUnknownInputStaysQuiet(): void
    {
        // A branch newer than the table, and garbage: neither is an alarm.
        $this->assertNull(PhpAdvisory::check('8.6.0'));
        $this->assertNull(PhpAdvisory::check('9.0.0'));
        $this->assertNull(PhpAdvisory::check(''));
        $this->assertNull(PhpAdvisory::check('onbekend'));
    }
}
