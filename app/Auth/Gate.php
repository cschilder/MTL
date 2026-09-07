<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Models\Model;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\CollaborationService;

defined('MTL_APP') || exit;

/**
 * Authorisation rules.
 *
 * Permissions are dotted strings ('trip.update', 'user.manage'). The role
 * matrix below grants them outright; anything an author may only do to their
 * own records is listed separately and checked against the record's owner.
 *
 * Keeping the whole policy in one readable table is the point — it is the
 * document you read to answer "who can delete a photo?".
 */
final class Gate
{
    /**
     * Permissions granted unconditionally, by role.
     *
     * @var array<string,list<string>>
     */
    private const MATRIX = [
        User::ROLE_ADMIN => ['*'],

        User::ROLE_EDITOR => [
            'admin.access',
            'trip.create', 'trip.update', 'trip.delete', 'trip.publish', 'trip.share',
            'step.create', 'step.update', 'step.delete', 'step.publish',
            'album.create', 'album.update', 'album.delete',
            'media.upload', 'media.update', 'media.delete',
            'tag.manage',
            'content.view_private',
            'search.reindex',
        ],

        User::ROLE_AUTHOR => [
            'admin.access',
            'trip.create',
            'step.create',
            'album.create',
            'media.upload',
        ],

        User::ROLE_VIEWER => [
            'content.view_private',
        ],
    ];

    /**
     * Permissions an author holds over records they own.
     *
     * @var list<string>
     */
    private const OWNER_PERMISSIONS = [
        'trip.update', 'trip.delete', 'trip.publish', 'trip.share',
        'step.update', 'step.delete', 'step.publish',
        'album.update', 'album.delete',
        'media.update', 'media.delete',
    ];

    /**
     * Permissions a travel companion holds over the trip they are linked to
     * (and its stops). Editing in full, and linking further companions —
     * "members link each other" is the point of the feature. What never
     * transfers: trip.delete and trip.publish. Whose trip it is and who sees
     * it stay with the owner, who can also always unlink anyone.
     *
     * @var list<string>
     */
    private const COLLABORATOR_PERMISSIONS = [
        'trip.update', 'trip.share',
        'step.create', 'step.update', 'step.delete', 'step.publish',
    ];

    /**
     * @param Model|null $subject the record being acted on, when there is one
     */
    public static function allows(?User $user, string $permission, mixed $subject = null): bool
    {
        if ($user === null || !$user->isActive()) {
            return false;
        }

        $granted = self::MATRIX[$user->role()] ?? [];

        if (in_array('*', $granted, true)) {
            return true;
        }

        // Creating a stop *in a particular trip* is not a general ability but
        // an act on that trip: it follows trip.update. Without this, any
        // author could add stops to journeys that are none of theirs.
        if ($permission === 'step.create' && $subject instanceof Trip) {
            return self::allows($user, 'trip.update', $subject);
        }

        if (in_array($permission, $granted, true)) {
            // Even a permission granted outright is refused on a record that
            // belongs to someone with more authority.
            return self::subjectIsReachable($user, $subject);
        }

        if ($subject !== null && in_array($permission, self::OWNER_PERMISSIONS, true) && self::owns($user, $subject)) {
            return true;
        }

        // Travel companions: linked to a trip, a member edits it as if it
        // were their own — whatever their role says otherwise.
        if (in_array($permission, self::COLLABORATOR_PERMISSIONS, true) && self::collaborates($user, $subject)) {
            return true;
        }

        // A companion also needs the door to the management screens and a way
        // to add their own photos, even when their role (a fresh registration
        // is a viewer) grants neither.
        if (in_array($permission, ['admin.access', 'media.upload'], true)) {
            return CollaborationService::hasAny($user);
        }

        return false;
    }

    /**
     * True when $user is linked to $subject as a travel companion — directly
     * for a trip, through the parent trip for a stop.
     */
    public static function collaborates(?User $user, mixed $subject): bool
    {
        if ($subject instanceof Trip) {
            return CollaborationService::isCollaborator($user, $subject->id());
        }

        if ($subject instanceof Step) {
            return CollaborationService::isCollaborator($user, $subject->int('trip_id'));
        }

        return false;
    }

    public static function denies(?User $user, string $permission, mixed $subject = null): bool
    {
        return !self::allows($user, $permission, $subject);
    }

    /**
     * True when $user created $subject.
     */
    public static function owns(?User $user, mixed $subject): bool
    {
        if ($user === null || !$subject instanceof Model) {
            return false;
        }

        $ownerId = $subject->int('user_id');

        return $ownerId > 0 && $ownerId === $user->id();
    }

    /**
     * Guards user-on-user actions: an editor may manage authors and viewers,
     * never another editor or an admin.
     */
    private static function subjectIsReachable(User $actor, mixed $subject): bool
    {
        if (!$subject instanceof User) {
            return true;
        }

        if ($actor->id() === $subject->id()) {
            return true;
        }

        return $actor->isAdmin() || $actor->outranks($subject);
    }

    /**
     * Whether $user may read a record that is not public.
     *
     * Visibility lives on the record; this answers the "signed in, but is it
     * theirs?" half.
     */
    public static function canViewPrivate(?User $user, mixed $subject = null): bool
    {
        if ($user === null || !$user->isActive()) {
            return false;
        }

        if (self::allows($user, 'content.view_private')) {
            return true;
        }

        return self::owns($user, $subject) || self::collaborates($user, $subject);
    }

    /**
     * The full permission list for a role, used by the management screens to
     * show what an account can do.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $granted = self::MATRIX[$role] ?? [];

        if ($granted === ['*']) {
            return self::allPermissions();
        }

        if ($role === User::ROLE_AUTHOR) {
            return array_values(array_unique(array_merge(
                $granted,
                array_map(static fn (string $p): string => $p . ' (own)', self::OWNER_PERMISSIONS)
            )));
        }

        return $granted;
    }

    /** @return list<string> */
    public static function allPermissions(): array
    {
        $all = [];

        foreach (self::MATRIX as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission !== '*') {
                    $all[] = $permission;
                }
            }
        }

        $all = array_merge($all, self::OWNER_PERMISSIONS, [
            'user.manage', 'settings.manage', 'maintenance.run', 'audit.view',
        ]);

        sort($all);

        return array_values(array_unique($all));
    }
}
