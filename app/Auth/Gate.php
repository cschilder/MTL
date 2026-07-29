<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Models\Model;
use MTL\Models\User;

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
            'trip.create', 'trip.update', 'trip.delete', 'trip.publish',
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
        'trip.update', 'trip.delete', 'trip.publish',
        'step.update', 'step.delete', 'step.publish',
        'album.update', 'album.delete',
        'media.update', 'media.delete',
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

        if (in_array($permission, $granted, true)) {
            // Even a permission granted outright is refused on a record that
            // belongs to someone with more authority.
            return self::subjectIsReachable($user, $subject);
        }

        if ($subject !== null && in_array($permission, self::OWNER_PERMISSIONS, true)) {
            return self::owns($user, $subject);
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

        return self::owns($user, $subject);
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
