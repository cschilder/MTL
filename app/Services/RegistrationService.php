<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Auth\Password;
use MTL\Models\User;

defined('MTL_APP') || exit;

/**
 * Self-registration, gated twice.
 *
 * The first gate is the owner's: the whole flow only exists while the
 * `registration.open` setting is on. The second is per account: a request
 * lands as status 'pending', which cannot sign in, until an administrator
 * approves it in the user management screen. A travel site is personal —
 * nobody appears in it without the owner saying so.
 */
final class RegistrationService
{
    public static function isOpen(): bool
    {
        return SettingsService::bool('registration.open', false);
    }

    /**
     * Registers a pending account.
     *
     * @param array{name:string,email:string,password:string} $input validated
     *
     * @throws \RuntimeException when registration is closed or the address is taken
     */
    public static function register(array $input): User
    {
        if (!self::isOpen()) {
            throw new \RuntimeException(__('auth.register_closed'));
        }

        $email = mb_strtolower(trim($input['email']), 'UTF-8');

        if (User::query()->where('email', '=', $email)->first() !== null) {
            // The same wording as a success would leak which addresses have
            // accounts; but this form is admin-approved anyway, and a person
            // who forgot they registered is better served by the truth.
            throw new \RuntimeException(__('auth.register_taken'));
        }

        $user = User::create([
            'name'          => trim($input['name']),
            'email'         => $email,
            'password_hash' => Password::hash($input['password']),
            'role'          => self::defaultRole(),
            'status'        => 'pending',
        ]);

        AuditService::log('user.registered', $user, ['email' => $email], $user);

        self::notifyAdministrators($user);

        return $user;
    }

    /** An administrator lets the request in. */
    public static function approve(User $user, User $approvedBy): void
    {
        $user->update(['status' => 'active']);

        AuditService::log('user.approved', $user, [], $approvedBy);

        MailService::send(
            $user->string('email'),
            __('auth.approved_subject', ['site' => setting('site.title', 'MTL')]),
            __('auth.approved_body', [
                'name' => $user->displayName(),
                'url'  => url('/login'),
            ])
        );
    }

    private static function defaultRole(): string
    {
        $role = SettingsService::string('registration.default_role', 'author');

        // Whatever the setting says, a self-registered stranger never starts
        // as an administrator.
        return in_array($role, ['editor', 'author', 'viewer'], true) ? $role : 'viewer';
    }

    /** Best effort: an approval nobody knows about never happens. */
    private static function notifyAdministrators(User $user): void
    {
        $admins = User::query()
            ->where('role', '=', 'admin')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->get();

        foreach ($admins as $row) {
            MailService::send(
                (string) $row['email'],
                __('auth.pending_subject', ['site' => setting('site.title', 'MTL')]),
                __('auth.pending_body', [
                    'name'  => $user->displayName(),
                    'email' => $user->string('email'),
                    'url'   => url('/admin/users'),
                ])
            );
        }
    }
}
