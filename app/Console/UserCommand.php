<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Auth\Password;
use MTL\Models\User;
use MTL\Services\AuditService;

defined('MTL_APP') || exit;

/**
 * Account management from the shell.
 */
final class UserCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param list<string>              $args
     * @param array<string,string|true> $options
     */
    public function create(array $args, array $options): int
    {
        $email = $args[0] ?? $this->out->ask('E-mail');
        $name = $args[1] ?? $this->out->ask('Name', explode('@', $email)[0]);
        $role = (string) ($options['role'] ?? 'admin');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->out->error('That is not a valid e-mail address.');

            return 1;
        }

        if (!in_array($role, User::ROLES, true)) {
            $this->out->error('Unknown role. Choose one of: ' . implode(', ', User::ROLES));

            return 1;
        }

        if (User::findByEmail($email) !== null) {
            $this->out->error('An account with that address already exists.');

            return 1;
        }

        $password = is_string($options['password'] ?? null)
            ? (string) $options['password']
            : $this->out->askHidden('Password');

        if (strlen($password) < 10) {
            $this->out->error('Choose a password of at least 10 characters.');

            return 1;
        }

        $user = User::create([
            'name'              => $name,
            'email'             => strtolower($email),
            'password_hash'     => Password::hash($password),
            'role'              => $role,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        AuditService::log('user.created', $user, ['role' => $role, 'via' => 'console'], $user);

        $this->out->success('Created ' . $user->string('email') . ' (' . $role . ').');

        return 0;
    }

    public function list(): int
    {
        $rows = [];

        foreach (User::query()->orderBy('name')->get() as $row) {
            $user = User::fromRow($row);

            $rows[] = [
                (string) $user->id(),
                $user->displayName(),
                $user->string('email'),
                $user->role(),
                $user->string('status') . ($user->isDeleted() ? ' (deleted)' : ''),
                $user->date('last_login_at')?->format('Y-m-d H:i') ?? 'never',
            ];
        }

        if ($rows === []) {
            $this->out->info('No accounts yet. Create one with: php bin/console.php user:create');

            return 0;
        }

        $this->out->table(['ID', 'Name', 'E-mail', 'Role', 'Status', 'Last sign-in'], $rows);

        return 0;
    }

    /**
     * @param list<string> $args
     */
    public function password(array $args): int
    {
        $email = $args[0] ?? $this->out->ask('E-mail');

        $user = User::findByEmail($email);

        if ($user === null) {
            $this->out->error('No account with that address.');

            return 1;
        }

        $password = $this->out->askHidden('New password');

        if (strlen($password) < 10) {
            $this->out->error('Choose a password of at least 10 characters.');

            return 1;
        }

        $user->update([
            'password_hash'   => Password::hash($password),
            'status'          => $user->string('status') === 'invited' ? 'active' : $user->string('status'),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ]);

        // Every remembered device is signed out, which is the point of
        // resetting a password from the shell.
        db()->table('user_tokens')->where('user_id', '=', $user->id())->where('type', '=', 'remember')->delete();

        AuditService::log('user.password_changed', $user, ['via' => 'console'], $user);

        $this->out->success('Password updated for ' . $user->string('email') . '.');

        return 0;
    }

    /**
     * @param list<string> $args
     */
    public function role(array $args): int
    {
        $email = $args[0] ?? $this->out->ask('E-mail');
        $role = $args[1] ?? $this->out->ask('Role', 'admin');

        if (!in_array($role, User::ROLES, true)) {
            $this->out->error('Unknown role. Choose one of: ' . implode(', ', User::ROLES));

            return 1;
        }

        $user = User::findByEmail($email);

        if ($user === null) {
            $this->out->error('No account with that address.');

            return 1;
        }

        // The shell is the recovery path, but it still must not be able to
        // leave the site with no administrator at all.
        if ($user->isAdmin() && $role !== User::ROLE_ADMIN && User::activeAdminCount() <= 1) {
            $this->out->error('This is the last administrator; promote someone else first.');

            return 1;
        }

        $user->update(['role' => $role]);

        AuditService::log('user.role_changed', $user, ['role' => $role, 'via' => 'console'], $user);

        $this->out->success($user->string('email') . ' is now ' . $role . '.');

        return 0;
    }
}
