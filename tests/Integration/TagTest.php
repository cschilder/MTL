<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Core\Database;
use MTL\Models\Tag;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Tags.
 *
 * This path had no coverage at all, and paid for it: the first tag anyone ever
 * created was created in production, where the insert failed on an updated_at
 * column the tags table deliberately does not have. Every test here runs the
 * real statements against the real schema, so a model writing a column that
 * does not exist can no longer wait for a visitor to find it.
 */
final class TagTest extends TestCase
{
    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['taggables', 'tags'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }
    }

    /** The production incident: the very first insert into the tags table. */
    public function testATagCanBeCreated(): void
    {
        $tag = Tag::findOrCreate('Zuid-Amerika');

        $this->assertSame('Zuid-Amerika', $tag->string('name'));
        $this->assertSame('zuid-amerika', $tag->string('slug'));
        $this->assertSame(0, $tag->int('usage_count'));
    }

    /** Spelling variants land on the same tag: matching is by slug. */
    public function testFindOrCreateDeduplicatesBySlug(): void
    {
        $first = Tag::findOrCreate('Zuid-Amerika');
        $second = Tag::findOrCreate('zuid amerika');
        $third = Tag::findOrCreate('  Zuid  Amerika  ');

        $this->assertSame($first->id(), $second->id());
        $this->assertSame($first->id(), $third->id());
        $this->assertCount(1, Tag::query()->get());
    }

    public function testSyncAttachesDetachesAndCounts(): void
    {
        Tag::sync('trip', 1, ['IJsland', 'winter']);

        $names = static fn (): array => array_map(
            static fn (array $row): string => (string) $row['slug'],
            Database::instance()->table('tags')->where('usage_count', '>', 0)->orderBy('slug')->get()
        );

        $this->assertSame(['ijsland', 'winter'], $names());

        // Replacing the set detaches what is no longer wanted and refreshes
        // the counters on both sides.
        Tag::sync('trip', 1, ['winter', 'sneeuw']);

        $this->assertSame(['sneeuw', 'winter'], $names());

        $ijsland = Tag::findOrCreate('IJsland');
        $this->assertSame(0, Tag::find($ijsland->id())->int('usage_count'));
    }

    public function testSyncIsIdempotent(): void
    {
        Tag::sync('trip', 1, ['IJsland']);
        Tag::sync('trip', 1, ['IJsland']);

        $this->assertCount(1, Database::instance()->table('taggables')->get());
        $this->assertSame(1, Tag::findOrCreate('IJsland')->int('usage_count'));
    }

    public function testBlankNamesAreIgnored(): void
    {
        // '™' is deliberately absent here: transliteration turns it into a
        // legitimate "tm". Only names with no sluggable content at all are
        // dropped.
        Tag::sync('trip', 1, ['', '   ', '###', 'echt']);

        $this->assertCount(1, Tag::query()->get());
    }
}
