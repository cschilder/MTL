<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\Gate;
use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\CollaborationService;
use MTL\Services\TripService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Travel companions.
 *
 * The promises pinned here: a linked member edits the trip and its stops as
 * if they were their own — whatever their role — and may link further
 * companions, while deleting and publishing stay with the owner; and an
 * unlinked member gains nothing at all.
 */
final class CollaborationTest extends TestCase
{
    private User $owner;
    private User $friend;

    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['trip_collaborators', 'step_media', 'steps', 'trips', 'audit_log', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        CollaborationService::flush();

        $this->owner = $this->makeUser('eigenaar@example.com', User::ROLE_AUTHOR);

        // Deliberately a viewer: the role a fresh self-registration gets.
        // Being linked is what grants the editing, not the role.
        $this->friend = $this->makeUser('reisgenoot@example.com', User::ROLE_VIEWER);
    }

    protected function tearDown(): void
    {
        CollaborationService::flush();
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name'          => ucfirst(explode('@', $email)[0]),
            'email'         => $email,
            'password_hash' => Password::hash('reis-om-de-wereld-in-80-dagen'),
            'role'          => $role,
            'status'        => 'active',
        ]);
    }

    private function makeTrip(User $owner): Trip
    {
        return TripService::create([
            'title'      => 'Samen naar Edinburgh',
            'visibility' => 'private',
        ], $owner);
    }

    public function testALinkedMemberEditsTheTripAndItsStops(): void
    {
        $trip = $this->makeTrip($this->owner);

        $step = Step::create([
            'uuid'    => 'c0ffee00-0000-4000-8000-000000000001',
            'trip_id' => $trip->id(),
            'user_id' => $this->owner->id(),
            'title'   => 'Leith',
            'slug'    => 'leith',
            'status'  => 'draft',
        ]);

        // Before the link: nothing.
        $this->assertFalse(Gate::allows($this->friend, 'trip.update', $trip));
        $this->assertFalse(Gate::allows($this->friend, 'step.update', $step));
        $this->assertFalse(Gate::allows($this->friend, 'admin.access'));

        CollaborationService::add($trip, $this->friend, $this->owner);

        // After: editing in full, including the management door and photos —
        // even for a viewer.
        $this->assertTrue(Gate::allows($this->friend, 'trip.update', $trip));
        $this->assertTrue(Gate::allows($this->friend, 'step.create', $trip));
        $this->assertTrue(Gate::allows($this->friend, 'step.update', $step));
        $this->assertTrue(Gate::allows($this->friend, 'step.publish', $step));
        $this->assertTrue(Gate::allows($this->friend, 'admin.access'));
        $this->assertTrue(Gate::allows($this->friend, 'media.upload'));
        $this->assertTrue(Gate::canViewPrivate($this->friend, $trip));

        // Companions link each other: the linked member may bring in a third.
        $this->assertTrue(Gate::allows($this->friend, 'trip.share', $trip));

        $third = $this->makeUser('derde@example.com', User::ROLE_VIEWER);
        CollaborationService::add($trip, $third, $this->friend);
        $this->assertTrue(Gate::allows($third, 'trip.update', $trip));

        // But never the owner's prerogatives.
        $this->assertFalse(Gate::allows($this->friend, 'trip.delete', $trip));
        $this->assertFalse(Gate::allows($this->friend, 'trip.publish', $trip));
    }

    public function testTheOwnerLinksAndUnlinksTheirOwnTrip(): void
    {
        $trip = $this->makeTrip($this->owner);

        // The point of the feature: a plain author manages the links on their
        // own trip, no administrator required.
        $this->assertTrue(Gate::allows($this->owner, 'trip.share', $trip));

        CollaborationService::add($trip, $this->friend, $this->owner);
        $this->assertSame([$this->friend->id()], array_map(
            static fn (User $u): int => $u->id(),
            CollaborationService::usersFor($trip)
        ));

        // Linking twice stays one link; linking the owner is refused.
        CollaborationService::add($trip, $this->friend, $this->owner);
        CollaborationService::add($trip, $this->owner, $this->owner);
        $this->assertSame(1, count(CollaborationService::usersFor($trip)));

        CollaborationService::remove($trip, $this->friend, $this->owner);
        $this->assertSame([], CollaborationService::usersFor($trip));
        $this->assertFalse(Gate::allows($this->friend, 'trip.update', $trip));
    }

    public function testAStrangerAuthorCannotAddStopsToSomeoneElsesTrip(): void
    {
        $trip = $this->makeTrip($this->owner);
        $stranger = $this->makeUser('vreemde@example.com', User::ROLE_AUTHOR);

        $this->assertFalse(Gate::allows($stranger, 'step.create', $trip));
        $this->assertTrue(Gate::allows($stranger, 'step.create'));
    }

    public function testASharedPrivateTripAppearsInTheCompanionsListing(): void
    {
        $trip = $this->makeTrip($this->owner);

        $listed = static fn (User $user): array => array_map(
            static fn (Trip $t): int => $t->id(),
            TripService::visibleTrips($user)
        );

        // Viewers hold content.view_private, so use a second author — a role
        // whose listing genuinely depends on ownership — as the companion.
        $companion = $this->makeUser('genoot@example.com', User::ROLE_AUTHOR);

        $this->assertFalse(in_array($trip->id(), $listed($companion), true));

        CollaborationService::add($trip, $companion, $this->owner);

        $this->assertTrue(in_array($trip->id(), $listed($companion), true));
    }
}
