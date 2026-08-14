<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Database;
use MTL\Models\Trip;
use MTL\Models\User;

defined('MTL_APP') || exit;

/**
 * Travel companions: members linked to a trip to edit it together.
 *
 * The link is the whole model — a companion holds no extra role and no row in
 * any permission table. The Gate asks this service "may this user act on that
 * trip?" and everything else (the trip forms, the stop forms, the listings)
 * follows from the answer.
 */
final class CollaborationService
{
    /**
     * Trip ids by user id, filled once per request. The Gate consults the
     * collaboration on every permission check in a listing, and fifty rows
     * must not mean fifty queries.
     *
     * @var array<int, list<int>>
     */
    private static array $tripIds = [];

    /** @return list<int> ids of trips this user is linked to */
    public static function tripIdsFor(User|int $user): array
    {
        $id = $user instanceof User ? $user->id() : $user;

        if ($id <= 0) {
            return [];
        }

        if (!isset(self::$tripIds[$id])) {
            self::$tripIds[$id] = array_map('intval', Database::instance()
                ->table('trip_collaborators')
                ->where('user_id', '=', $id)
                ->pluck('trip_id'));
        }

        return self::$tripIds[$id];
    }

    public static function isCollaborator(?User $user, int $tripId): bool
    {
        return $user !== null && $tripId > 0 && in_array($tripId, self::tripIdsFor($user), true);
    }

    /** Whether this user is linked to any trip at all. */
    public static function hasAny(?User $user): bool
    {
        return $user !== null && self::tripIdsFor($user) !== [];
    }

    /**
     * The linked members of a trip, oldest link first.
     *
     * @return list<User>
     */
    public static function usersFor(Trip $trip): array
    {
        $ids = array_map('intval', Database::instance()
            ->table('trip_collaborators')
            ->where('trip_id', '=', $trip->id())
            ->orderBy('created_at')
            ->pluck('user_id'));

        if ($ids === []) {
            return [];
        }

        $users = User::fromRows(User::query()->whereIn('id', $ids)->get());

        // Back into link order: the query returns them in id order.
        usort($users, static fn (User $a, User $b): int =>
            array_search($a->id(), $ids, true) <=> array_search($b->id(), $ids, true));

        return $users;
    }

    /**
     * Links a member to the trip. Linking someone twice is a no-op, and the
     * owner is never linked to their own trip.
     */
    public static function add(Trip $trip, User $user, User $actor): void
    {
        if ($user->id() === $trip->int('user_id')) {
            return;
        }

        if (self::isCollaborator($user, $trip->id())) {
            return;
        }

        Database::instance()->table('trip_collaborators')->insert([
            'trip_id'    => $trip->id(),
            'user_id'    => $user->id(),
            'added_by'   => $actor->id(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        self::flush();

        AuditService::log('trip.collaborator_added', $trip, [
            'user_id' => $user->id(),
            'email'   => $user->string('email'),
        ], $actor);
    }

    public static function remove(Trip $trip, User $user, User $actor): void
    {
        Database::instance()->table('trip_collaborators')
            ->where('trip_id', '=', $trip->id())
            ->where('user_id', '=', $user->id())
            ->delete();

        self::flush();

        AuditService::log('trip.collaborator_removed', $trip, [
            'user_id' => $user->id(),
            'email'   => $user->string('email'),
        ], $actor);
    }

    /** Clears the per-request cache. Called after writes and between tests. */
    public static function flush(): void
    {
        self::$tripIds = [];
    }
}
