<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\AuthManager;
use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\GlobeService;
use MTL\Services\StepService;
use MTL\Services\TripService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * What the globe shows, and to whom.
 *
 * The rule that needed writing down: a stop without coordinates is not on the
 * globe, and a trip whose stops all lack them is skipped entirely. That is
 * correct — there is nothing to draw — but it is also exactly what a new user
 * runs into after typing their first trip, so the behaviour is pinned here and
 * explained in the management screens.
 */
final class GlobeTest extends TestCase
{
    private User $author;

    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['step_media', 'steps', 'trips', 'audit_log', 'search_index', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        $this->author = User::create([
            'name'              => 'Reiziger',
            'email'             => 'reiziger@example.com',
            'password_hash'     => Password::hash('reis-door-de-wereld-2026'),
            'role'              => User::ROLE_AUTHOR,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $auth = new AuthManager();
        $auth->setUser($this->author);
        AuthManager::swap($auth);
    }

    protected function tearDown(): void
    {
        AuthManager::swap(null);
    }

    private function makeTrip(array $input = []): Trip
    {
        return TripService::create($input + [
            'title'      => 'IJsland in de winter',
            'body_md'    => '',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PUBLIC,
        ], $this->author);
    }

    /** @return list<string> trip titles in the payload */
    private function titles(?User $viewer): array
    {
        return array_map(
            static fn (array $trip): string => (string) $trip['title'],
            GlobeService::payload($viewer)['trips']
        );
    }

    /**
     * The support question this file exists for: a trip whose stops have no
     * coordinates has nothing to draw and is skipped — visibly explained in
     * the management screens, and pinned here so the rule cannot drift
     * silently.
     */
    public function testATripWhoseStopsLackCoordinatesIsNotOnTheGlobe(): void
    {
        $trip = $this->makeTrip();

        StepService::create($trip, [
            'title'   => 'Keflavík',
            'body_md' => '',
            'status'  => 'published',
        ], $this->author);

        $this->assertFalse(in_array('IJsland in de winter', $this->titles(null), true));
        $this->assertSame(0, GlobeService::payload(null)['stats']['trips']);
    }

    public function testGivingAStopCoordinatesPutsTheTripOnTheGlobe(): void
    {
        $trip = $this->makeTrip();

        $step = StepService::create($trip, [
            'title'   => 'Keflavík',
            'body_md' => '',
            'status'  => 'published',
        ], $this->author);

        // The moment the stop gets coordinates, the trip appears.
        StepService::update($step, ['latitude' => 63.985, 'longitude' => -22.6056]);

        $payload = GlobeService::payload(null);

        $this->assertSame(['IJsland in de winter'], $this->titles(null));
        $this->assertSame(63.985, $payload['trips'][0]['steps'][0]['lat']);
        $this->assertSame(-22.6056, $payload['trips'][0]['steps'][0]['lon']);
    }

    /** A draft is the owner's business: on their globe, not on a visitor's. */
    public function testADraftTripIsOnTheOwnersGlobeButNotAVisitors(): void
    {
        $trip = $this->makeTrip(['title' => 'Nog geheim', 'status' => Trip::STATUS_DRAFT]);

        StepService::create($trip, [
            'title'     => 'Keflavík',
            'body_md'   => '',
            'status'    => 'published',
            'latitude'  => 63.985,
            'longitude' => -22.6056,
        ], $this->author);

        $this->assertFalse(in_array('Nog geheim', $this->titles(null), true), 'a visitor must not see it');
        $this->assertTrue(in_array('Nog geheim', $this->titles($this->author), true), 'the owner must');
    }

    /** Draft stops inside a published trip stay off a visitor's globe too. */
    public function testADraftStopIsOnlyDrawnForItsOwner(): void
    {
        $trip = $this->makeTrip();

        StepService::create($trip, [
            'title'     => 'Al te zien',
            'body_md'   => '',
            'status'    => 'published',
            'latitude'  => 63.985,
            'longitude' => -22.6056,
        ], $this->author);

        StepService::create($trip, [
            'title'     => 'Nog niet klaar',
            'body_md'   => '',
            'status'    => 'draft',
            'latitude'  => 64.1466,
            'longitude' => -21.9426,
        ], $this->author);

        $forVisitor = GlobeService::payload(null)['trips'][0]['steps'];
        $forOwner = GlobeService::payload($this->author)['trips'][0]['steps'];

        $this->assertCount(1, $forVisitor);
        $this->assertCount(2, $forOwner);
    }
}
