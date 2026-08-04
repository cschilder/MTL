<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Support\Countries;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Country codes become names.
 *
 * Born from a support question: a stop showed its country as "IS", which is
 * Iceland to a machine and a mystery to a person.
 */
final class CountriesTest extends TestCase
{
    public function testACodeResolvesToItsLocalisedName(): void
    {
        $this->assertSame('IJsland', Countries::name('IS', 'nl'));
        $this->assertSame('United Kingdom', Countries::name('GB', 'en'));

        // Case and whitespace are the caller's problem no longer.
        $this->assertSame('IJsland', Countries::name(' is ', 'nl'));
    }

    public function testUnknownAndEmptyCodesPassThroughHonestly(): void
    {
        $this->assertSame('XX', Countries::name('XX', 'nl'));
        $this->assertSame('', Countries::name('', 'nl'));
    }

    public function testTheFullListIsCompleteAndSortedByName(): void
    {
        $list = Countries::all('nl');

        $this->assertSame(249, count($list));
        $this->assertSame('IJsland', $list['IS']);
        $this->assertTrue(isset($list['GB'], $list['NL'], $list['US']));

        // Sorted by the visible name, so the dropdown reads like a list of
        // countries rather than of codes.
        $names = array_values($list);
        $sorted = $names;
        $collator = new \Collator('nl');
        usort($sorted, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        $this->assertSame($sorted, $names);
    }
}
