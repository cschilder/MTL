<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Auth\Gate;
use MTL\Core\Database;
use MTL\Core\HttpException;
use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Auth\PasswordController;
use MTL\Http\Controllers\Controller;
use MTL\Models\User;
use MTL\Services\AuditService;
use MTL\Services\MailService;
use MTL\Services\SettingsService;

defined('MTL_APP') || exit;

/**
 * User management.
 *
 * Two rules run through everything here, and they are the reason several
 * actions refuse in ways that look fussy:
 *
 *   - nobody may act on an account with more authority than their own, so an
 *     editor cannot edit, disable or delete an administrator;
 *   - the last active administrator cannot be removed, demoted or disabled,
 *     because that would leave the installation with no way back in.
 */
final class UserAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::query()->orderBy('name');

        if ($request->string('trashed') === '1') {
            $query->whereNotNull('deleted_at');
        } else {
            $query->whereNull('deleted_at');
        }

        $search = $request->string('q');

        if ($search !== '') {
            $query->whereGroup(static function (QueryBuilder $q) use ($search): void {
                $q->whereLike('name', $search)->whereLike('email', $search, 'OR');
            });
        }

        $role = $request->string('role');

        if (in_array($role, User::ROLES, true)) {
            $query->where('role', '=', $role);
        }

        $result = $query->paginate($this->page($request), 40);

        return view('admin/users/index', [
            'title'      => __('user.users'),
            'noindex'    => true,
            'users'      => User::fromRows($result['data']),
            'pagination' => $result,
            'filters'    => ['q' => $search, 'role' => $role, 'trashed' => $request->string('trashed')],
            'adminCount' => User::activeAdminCount(),
        ]);
    }

    public function create(Request $request): Response
    {
        return view('admin/users/edit', [
            'title'   => __('user.new'),
            'noindex' => true,
            'user'    => null,
            'roles'   => $this->assignableRoles(),
            'action'  => path('/admin/users'),
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireUser();

        $data = $this->validate($request, [
            'name'   => 'required|string|min:2|max:120',
            'email'  => 'required|email|unique:users,email',
            'role'   => 'required|string|in:' . implode(',', User::ROLES),
            'status' => 'nullable|string|in:invited,active,disabled',
        ]);

        $this->assertRoleAssignable($actor, (string) $data['role']);

        $user = User::create([
            'name'   => $data['name'],
            'email'  => $data['email'],
            'role'   => $data['role'],
            // A new account has no password: the invitation link sets one.
            'status' => $data['status'] ?? 'invited',
            'locale' => SettingsService::string('site.language', 'nl'),
            'timezone' => SettingsService::string('site.timezone', 'Europe/Amsterdam'),
        ]);

        AuditService::log('user.created', $user, ['role' => $data['role']]);

        $this->sendInvitation($user, $actor);

        return $this->back(path('/admin/users/' . $user->id()), __('user.created'));
    }

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'), true);

        $this->assertMayManage($user);

        return view('admin/users/edit', [
            'title'       => $user->displayName(),
            'noindex'     => true,
            'user'        => $user,
            'roles'       => $this->assignableRoles(),
            'permissions' => Gate::permissionsFor($user->role()),
            'sessions'    => Database::instance()->table('user_tokens')
                ->where('user_id', '=', $user->id())
                ->where('type', '=', 'remember')
                ->get(),
            'recent'      => AuditService::query(['user_id' => $user->id()])->limit(15)->get(),
            'action'      => path('/admin/users/' . $user->id()),
        ]);
    }

    public function update(Request $request): Response
    {
        $actor = $this->requireUser();

        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'), true);

        $this->assertMayManage($user);

        $data = $this->validate($request, [
            'name'   => 'required|string|min:2|max:120',
            'email'  => 'required|email|unique:users,email,' . $user->id(),
            'role'   => 'required|string|in:' . implode(',', User::ROLES),
            'status' => 'required|string|in:invited,active,disabled',
            'bio'    => 'nullable|string|max:500',
        ]);

        $before = $user->raw();

        $newRole = (string) $data['role'];
        $newStatus = (string) $data['status'];

        if ($newRole !== $user->role()) {
            $this->assertRoleAssignable($actor, $newRole);
            $this->assertNotLastAdmin($user, $newRole, $newStatus);
        }

        if ($newStatus !== $user->string('status')) {
            $this->assertNotLastAdmin($user, $newRole, $newStatus);
        }

        $values = [
            'name'   => $data['name'],
            'email'  => strtolower((string) $data['email']),
            'role'   => $newRole,
            'status' => $newStatus,
            'bio'    => $data['bio'] ?? '',
        ];

        $user->update($values);

        // A disabled account keeps its session cookie until it expires, so the
        // remembered devices have to go with it.
        if ($newStatus === 'disabled') {
            $this->revokeTokensFor($user);
        }

        AuditService::logChange('user.updated', $user, $before, $values);

        return $this->back(path('/admin/users/' . $user->id()), __('user.updated'));
    }

    public function destroy(Request $request): Response
    {
        $actor = $this->requireUser();

        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'), true);

        $this->assertMayManage($user);

        if ($user->id() === $actor->id()) {
            return $this->back(path('/admin/users'), __('user.self_delete'), 'negative');
        }

        $this->assertNotLastAdmin($user, $user->role(), 'disabled');

        // Soft delete: the trips, steps and photos this person wrote keep their
        // author, and a deletion made in error is recoverable.
        $user->softDelete();
        $this->revokeTokensFor($user);

        AuditService::log('user.deleted', $user);

        return $this->back(path('/admin/users'), __('user.deleted'), 'information');
    }

    public function restore(Request $request): Response
    {
        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'), true);

        $this->assertMayManage($user);

        $user->restore();

        AuditService::log('user.restored', $user);

        return $this->back(path('/admin/users/' . $user->id()), __('user.updated'));
    }

    public function resendInvite(Request $request): Response
    {
        $actor = $this->requireUser();

        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'));

        $this->assertMayManage($user);

        $this->sendInvitation($user, $actor);

        return $this->back(path('/admin/users/' . $user->id()), __('user.invited'));
    }

    /**
     * Ends every remembered session for an account.
     */
    public function revokeSessions(Request $request): Response
    {
        /** @var User $user */
        $user = $this->findOrFail(User::class, (int) $request->param('id', '0'));

        $this->assertMayManage($user);

        $this->revokeTokensFor($user);

        AuditService::log('user.sessions_revoked', $user);

        return $this->back(path('/admin/users/' . $user->id()), __('user.sessions_revoked'));
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    private function assertMayManage(User $subject): void
    {
        $actor = $this->requireUser();

        if ($actor->id() === $subject->id()) {
            return;
        }

        if (!$actor->isAdmin() && !$actor->outranks($subject)) {
            throw HttpException::forbidden(__('error.forbidden'));
        }
    }

    /** Nobody may hand out a role above their own. */
    private function assertRoleAssignable(User $actor, string $role): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $actorRank = array_search($actor->role(), User::ROLES, true);
        $targetRank = array_search($role, User::ROLES, true);

        if ($targetRank === false || $targetRank <= $actorRank) {
            throw HttpException::forbidden(__('error.forbidden'));
        }
    }

    /**
     * Refuses a change that would leave no active administrator.
     */
    private function assertNotLastAdmin(User $subject, string $newRole, string $newStatus): void
    {
        if (!$subject->isAdmin() || $subject->string('status') !== 'active') {
            return;
        }

        $staysAdmin = $newRole === User::ROLE_ADMIN && $newStatus === 'active';

        if ($staysAdmin) {
            return;
        }

        if (User::activeAdminCount() <= 1) {
            throw new HttpException(422, __('user.last_admin'));
        }
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(): array
    {
        $actor = $this->requireUser();

        if ($actor->isAdmin()) {
            return User::ROLES;
        }

        $rank = array_search($actor->role(), User::ROLES, true);

        return array_values(array_slice(User::ROLES, (int) $rank + 1));
    }

    private function revokeTokensFor(User $user): void
    {
        Database::instance()->table('user_tokens')
            ->where('user_id', '=', $user->id())
            ->where('type', '=', 'remember')
            ->delete();
    }

    private function sendInvitation(User $user, User $actor): void
    {
        $token = (new PasswordController())->issueInviteToken($user);

        MailService::sendInvitation(
            $user->string('email'),
            $user->displayName(),
            url('/invite/' . $token),
            $actor->displayName(),
            PasswordController::inviteDays()
        );

        AuditService::log('user.invited', $user);
    }
}
