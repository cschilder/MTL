<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\AuthManager;
use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\StepService;
use MTL\Services\TripService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Trips and their stops.
 *
 * Three things matter here and none of them can be checked without a database:
 * that slugs stay unique, that the stored HTML is regenerated whenever the
 * markdown changes, and that a draft or a private trip is invisible to someone
 * who should not see it.
 */
final class ContentTest extends TestCase
{
    private User $author;

    private User $otherAuthor;

    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['step_media', 'steps', 'trips', 'audit_log', 'search_index', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        $this->author = $this->makeUser('reiziger@example.com');
        $this->otherAuthor = $this->makeUser('ander@example.com');

        $auth = new AuthManager();
        $auth->setUser($this->author);
        AuthManager::swap($auth);
    }

    protected function tearDown(): void
    {
        AuthManager::swap(null);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name'              => explode('@', $email)[0],
            'email'             => $email,
            'password_hash'     => Password::hash('reis-door-de-wereld-2026'),
            'role'              => User::ROLE_AUTHOR,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function makeTrip(array $input = [], ?User $author = null): Trip
    {
        return TripService::create($input + [
            'title'      => 'IJsland in de winter',
            'body_md'    => 'Een reis van **tien** dagen.',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PUBLIC,
        ], $author ?? $this->author);
    }

    // -------------------------------------------------------------------------
    // Slugs
    // -------------------------------------------------------------------------

    public function testATripGetsASlugFromItsTitle(): void
    {
        $trip = $this->makeTrip(['title' => 'IJsland in de winter']);

        $this->assertSame('ijsland-in-de-winter', $trip->string('slug'));
    }

    /**
     * Two trips with the same name is ordinary — a yearly walk, say — and the
     * second must not overwrite the first's URL.
     */
    public function testASecondTripWithTheSameTitleGetsADistinctSlug(): void
    {
        $first = $this->makeTrip(['title' => 'Rota Vicentina']);
        $second = $this->makeTrip(['title' => 'Rota Vicentina']);

        $this->assertSame('rota-vicentina', $first->string('slug'));
        $this->assertNotSame($first->string('slug'), $second->string('slug'));
        $this->assertNotEmpty($second->string('slug'));
    }

    /** Accents and punctuation have to survive into something URL-safe. */
    public function testSlugsAreUrlSafe(): void
    {
        foreach ([
            'Þingvellir & Geysir'   => 'thingvellir-geysir',
            'Vík í Mýrdal'          => 'vik-i-myrdal',
            'Dag 1: aankomst!'      => 'dag-1-aankomst',
        ] as $title => $expected) {
            $trip = $this->makeTrip(['title' => $title]);

            $this->assertSame($expected, $trip->string('slug'), 'for title ' . $title);
        }
    }

    /**
     * Step slugs are unique per trip, not globally: two trips may both have a
     * stop called "Aankomst".
     */
    public function testStepSlugsAreUniqueWithinATripButMayRepeatAcrossTrips(): void
    {
        $first = $this->makeTrip(['title' => 'Eerste reis']);
        $second = $this->makeTrip(['title' => 'Tweede reis']);

        $a = StepService::create($first, ['title' => 'Aankomst', 'body_md' => ''], $this->author);
        $b = StepService::create($first, ['title' => 'Aankomst', 'body_md' => ''], $this->author);
        $c = StepService::create($second, ['title' => 'Aankomst', 'body_md' => ''], $this->author);

        $this->assertSame('aankomst', $a->string('slug'));
        $this->assertNotSame($a->string('slug'), $b->string('slug'));
        $this->assertSame('aankomst', $c->string('slug'), 'a different trip may reuse the slug');
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function testTheStoredHtmlIsRenderedOnCreate(): void
    {
        $step = StepService::create(
            $this->makeTrip(),
            ['title' => 'Keflavík', 'body_md' => 'Buiten was het **donker**.'],
            $this->author
        );

        $this->assertContains('<strong>donker</strong>', $step->string('body_html'));
        $this->assertNotEmpty($step->string('excerpt'));
        // The excerpt is for listings and meta tags, so it carries no markup.
        $this->assertNotContains('<strong>', $step->string('excerpt'));
    }

    public function testTheStoredHtmlIsRegeneratedOnUpdate(): void
    {
        $step = StepService::create(
            $this->makeTrip(),
            ['title' => 'Keflavík', 'body_md' => 'Eerst.'],
            $this->author
        );

        $updated = StepService::update($step, ['title' => 'Keflavík', 'body_md' => 'Daarna *anders*.']);

        $this->assertContains('<em>anders</em>', $updated->string('body_html'));
        $this->assertNotContains('Eerst.', $updated->string('body_html'));
    }

    /**
     * Raw HTML in a report is shown, not run. The renderer escapes it rather
     * than sanitising it, so there is no filter to get past.
     */
    public function testHtmlInAReportIsEscaped(): void
    {
        $step = StepService::create(
            $this->makeTrip(),
            ['title' => 'Keflavík', 'body_md' => 'Kijk: <script>alert(1)</script> en <img onerror=x>'],
            $this->author
        );

        $html = $step->string('body_html');

        $this->assertNotContains('<script>', $html);
        $this->assertNotContains('<img', $html);
        $this->assertContains('&lt;script&gt;', $html);
    }

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    public function testADraftTripIsHiddenFromVisitors(): void
    {
        $this->makeTrip(['title' => 'Nog niet klaar', 'status' => Trip::STATUS_DRAFT]);

        $titles = array_map(
            static fn (Trip $trip): string => $trip->string('title'),
            TripService::visibleTrips(null)
        );

        $this->assertFalse(in_array('Nog niet klaar', $titles, true));
    }

    public function testAPrivateTripIsHiddenFromVisitorsAndFromOtherAuthors(): void
    {
        $this->makeTrip([
            'title'      => 'Alleen voor mij',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PRIVATE,
        ]);

        $forVisitor = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips(null));
        $forOther = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips($this->otherAuthor));
        $forOwner = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips($this->author));

        $this->assertFalse(in_array('Alleen voor mij', $forVisitor, true), 'a visitor should not see it');
        $this->assertFalse(in_array('Alleen voor mij', $forOther, true), 'another author should not see it');
        $this->assertTrue(in_array('Alleen voor mij', $forOwner, true), 'the owner should see it');
    }

    public function testAPublishedPublicTripIsVisibleToEveryone(): void
    {
        $this->makeTrip(['title' => 'Voor iedereen']);

        $titles = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips(null));

        $this->assertTrue(in_array('Voor iedereen', $titles, true));
    }

    // -------------------------------------------------------------------------
    // Ordering and aggregates
    // -------------------------------------------------------------------------

    public function testStepsAreAppendedInOrder(): void
    {
        $trip = $this->makeTrip();

        $first = StepService::create($trip, ['title' => 'Een', 'body_md' => ''], $this->author);
        $second = StepService::create($trip, ['title' => 'Twee', 'body_md' => ''], $this->author);
        $third = StepService::create($trip, ['title' => 'Drie', 'body_md' => ''], $this->author);

        $this->assertSame(0, $first->int('position'));
        $this->assertSame(1, $second->int('position'));
        $this->assertSame(2, $third->int('position'));
    }

    public function testStepsCanBeReordered(): void
    {
        $trip = $this->makeTrip();

        $a = StepService::create($trip, ['title' => 'Een', 'body_md' => ''], $this->author);
        $b = StepService::create($trip, ['title' => 'Twee', 'body_md' => ''], $this->author);
        $c = StepService::create($trip, ['title' => 'Drie', 'body_md' => ''], $this->author);

        TripService::reorderSteps($trip, [$c->id(), $a->id(), $b->id()]);

        $order = array_map(
            static fn (array $row): string => (string) $row['title'],
            Step::query()->where('trip_id', '=', $trip->id())->orderBy('position')->get()
        );

        $this->assertSame(['Drie', 'Een', 'Twee'], $order);
    }

    /**
     * The distance shown on a trip is the sum of the great-circle hops between
     * consecutive stops that have coordinates.
     */
    public function testTripDistanceIsComputedFromStopCoordinates(): void
    {
        $trip = $this->makeTrip();

        // Keflavík to Reykjavík, roughly 37 km apart.
        StepService::create($trip, [
            'title' => 'Keflavík', 'body_md' => '', 'latitude' => 63.985, 'longitude' => -22.6056,
        ], $this->author);
        StepService::create($trip, [
            'title' => 'Reykjavík', 'body_md' => '', 'latitude' => 64.1466, 'longitude' => -21.9426,
        ], $this->author);

        TripService::refreshStepDistances($trip);
        TripService::refreshAggregates($trip);

        $fresh = Trip::find($trip->id());

        $this->assertNotNull($fresh);

        $km = (float) $fresh->attribute('distance_km', 0);

        $this->assertTrue($km > 30 && $km < 45, 'expected roughly 37 km, got ' . $km);
    }

    public function testTripStepCountTracksItsSteps(): void
    {
        $trip = $this->makeTrip();

        StepService::create($trip, ['title' => 'Een', 'body_md' => '', 'status' => Step::STATUS_PUBLISHED], $this->author);
        StepService::create($trip, ['title' => 'Twee', 'body_md' => '', 'status' => Step::STATUS_PUBLISHED], $this->author);

        TripService::refreshAggregates($trip);

        $fresh = Trip::find($trip->id());

        $this->assertNotNull($fresh);
        $this->assertSame(2, $fresh->int('step_count'));
    }

    // -------------------------------------------------------------------------
    // Partial updates
    // -------------------------------------------------------------------------

    /**
     * The production incident of 31 July: the edit form posts no `position`,
     * validation materialised the absent field as null, and the update wrote
     * that null into a NOT NULL column — every trip save answered 500 on a
     * strict-mode server. An update without a field must leave that field
     * exactly as it was.
     */
    public function testUpdatingATripWithoutPositionKeepsThePosition(): void
    {
        $trip = $this->makeTrip();
        $trip->update(['position' => 7]);

        $updated = TripService::update(Trip::find($trip->id()), [
            'title'   => 'Bijgewerkt',
            'body_md' => 'Nieuwe tekst.',
        ]);

        $this->assertSame('Bijgewerkt', $updated->string('title'));
        $this->assertSame(7, Trip::find($trip->id())->int('position'));
    }

    /** The null itself, as the old validator produced it, must also be inert. */
    public function testANullPositionOrVisibilityDoesNotReachTheDatabase(): void
    {
        $trip = $this->makeTrip();
        $trip->update(['position' => 3]);

        TripService::update(Trip::find($trip->id()), [
            'title'      => 'Nogmaals',
            'position'   => null,
            'visibility' => null,
        ]);

        $fresh = Trip::find($trip->id());

        $this->assertSame(3, $fresh->int('position'));
        $this->assertSame(Trip::VISIBILITY_PUBLIC, $fresh->string('visibility'));
    }

    public function testUpdatingAStepWithoutVisibilityKeepsIt(): void
    {
        $step = StepService::create(
            $this->makeTrip(),
            ['title' => 'Keflavík', 'body_md' => '', 'visibility' => 'public'],
            $this->author
        );

        StepService::update($step, ['title' => 'Keflavík', 'body_md' => 'Tekst.', 'visibility' => null]);

        $this->assertSame('public', Step::find($step->id())->string('visibility'));
    }

    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    /**
     * Deletion is soft. A travel report is the sort of thing someone deletes and
     * then wants back, so the row stays and is filtered out of every read.
     */
    public function testDeletingATripIsReversible(): void
    {
        $trip = $this->makeTrip(['title' => 'Per ongeluk']);

        TripService::delete($trip);

        $titles = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips($this->author));
        $this->assertFalse(in_array('Per ongeluk', $titles, true));

        $row = Trip::find($trip->id());
        $this->assertNotNull($row, 'the row should still exist');
        $this->assertNotNull($row->attribute('deleted_at'));

        TripService::restore($row);

        $after = array_map(static fn (Trip $t): string => $t->string('title'), TripService::visibleTrips($this->author));
        $this->assertTrue(in_array('Per ongeluk', $after, true));
    }

    public function testMovingAStepToAnotherTripPutsItAtTheEnd(): void
    {
        $from = $this->makeTrip(['title' => 'Van']);
        $to = $this->makeTrip(['title' => 'Naar']);

        StepService::create($to, ['title' => 'Bestaand', 'body_md' => ''], $this->author);
        $moved = StepService::create($from, ['title' => 'Verhuisd', 'body_md' => ''], $this->author);

        StepService::moveToTrip($moved, $to);

        $fresh = Step::find($moved->id());

        $this->assertNotNull($fresh);
        $this->assertSame($to->id(), $fresh->int('trip_id'));
        $this->assertSame(1, $fresh->int('position'), 'it should sit after the trip\'s existing stop');
    }
}
