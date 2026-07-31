<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Auth;

use MTL\Auth\Password;
use MTL\Auth\RateLimiter;
use MTL\Core\Database;
use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\User;
use MTL\Services\AuditService;
use MTL\Services\MailService;
use MTL\Support\Str;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * Password resets and invitation acceptance.
 *
 * Both are the same mechanism — a one-time token that authorises setting a
 * password — so they share the machinery and differ only in the message and
 * the lifetime.
 */
final class PasswordController extends Controller
{
    private const RESET_MINUTES = 60;
    private const INVITE_DAYS = 14;

    // -------------------------------------------------------------------------
    // Requesting a reset
    // -------------------------------------------------------------------------

    public function request(Request $request): Response
    {
        return view('auth/password-request', [
            'title'   => __('auth.forgot_title'),
            'noindex' => true,
        ]);
    }

    public function sendLink(Request $request): Response
    {
        $data = Validator::forRequest($request, ['email' => 'required|email'])->validated();

        $email = (string) $data['email'];

        // Rate limited per address and per IP: without it this endpoint is a
        // way to send mail to anyone, repeatedly.
        $buckets = ['reset:email:' . $email, 'reset:ip:' . $request->ip()];

        foreach ($buckets as $bucket) {
            if (!RateLimiter::hit($bucket, 5, 3600)) {
                return $this->back(
                    path('/password/forgot'),
                    __('auth.throttled', ['minutes' => (string) max(1, (int) ceil(RateLimiter::availableIn($bucket) / 60))]),
                    'caution'
                );
            }
        }

        $user = User::findByEmail($email);

        if ($user !== null && $user->string('status') !== 'disabled') {
            $token = $this->issueToken($user, 'password_reset', self::RESET_MINUTES * 60);

            MailService::sendPasswordReset(
                $user->string('email'),
                $user->displayName(),
                url('/password/reset/' . $token),
                self::RESET_MINUTES
            );

            AuditService::log('user.password_reset_requested', $user, [], $user);
        }

        // The same answer either way: a different response would turn this
        // form into a way to find out which addresses have accounts.
        return $this->back(path('/login'), __('auth.forgot_sent'), 'information');
    }

    // -------------------------------------------------------------------------
    // Completing a reset
    // -------------------------------------------------------------------------

    public function reset(Request $request): Response
    {
        $token = (string) $request->param('token', '');

        if ($this->findValidToken($token, 'password_reset') === null) {
            return $this->back(path('/password/forgot'), __('auth.reset_invalid'), 'negative');
        }

        return view('auth/password-reset', [
            'title'   => __('auth.reset_title'),
            'noindex' => true,
            'token'   => $token,
            'action'  => path('/password/reset'),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = Validator::forRequest($request, [
            'token'    => 'required|string|max:128|raw',
            'password' => 'required|string|password|confirmed|raw',
        ])->validated();

        $record = $this->findValidToken((string) $data['token'], 'password_reset');

        if ($record === null) {
            return $this->back(path('/password/forgot'), __('auth.reset_invalid'), 'negative');
        }

        $user = User::find((int) $record['user_id']);

        if ($user === null) {
            return $this->back(path('/password/forgot'), __('auth.reset_invalid'), 'negative');
        }

        $this->setPassword($user, (string) $data['password'], (int) $record['id']);

        AuditService::log('user.password_reset', $user, [], $user);

        return $this->back(path('/login'), __('auth.reset_done'));
    }

    // -------------------------------------------------------------------------
    // Invitations
    // -------------------------------------------------------------------------

    public function invite(Request $request): Response
    {
        $token = (string) $request->param('token', '');

        $record = $this->findValidToken($token, 'invite');

        if ($record === null) {
            return $this->back(path('/login'), __('auth.reset_invalid'), 'negative');
        }

        $user = User::find((int) $record['user_id']);

        return view('auth/invite', [
            'title'   => __('auth.invite_title'),
            'noindex' => true,
            'token'   => $token,
            'action'  => path('/invite'),
            'name'    => $user?->displayName() ?? '',
        ]);
    }

    public function acceptInvite(Request $request): Response
    {
        $data = Validator::forRequest($request, [
            'token'    => 'required|string|max:128|raw',
            'password' => 'required|string|password|confirmed|raw',
            'name'     => 'nullable|string|max:120',
        ])->validated();

        $record = $this->findValidToken((string) $data['token'], 'invite');

        if ($record === null) {
            return $this->back(path('/login'), __('auth.reset_invalid'), 'negative');
        }

        $user = User::find((int) $record['user_id']);

        if ($user === null) {
            return $this->back(path('/login'), __('auth.reset_invalid'), 'negative');
        }

        $values = ['status' => 'active', 'email_verified_at' => gmdate('Y-m-d H:i:s')];

        if (($data['name'] ?? '') !== '') {
            $values['name'] = (string) $data['name'];
        }

        $user->update($values);

        $this->setPassword($user, (string) $data['password'], (int) $record['id']);

        AuditService::log('user.invitation_accepted', $user, [], $user);

        // The account is ready, so sign them straight in rather than sending
        // them to a login form they have no reason to see.
        $this->auth()->login($user->fresh() ?? $user, false, $request);

        return $this->back(path('/admin'), __('auth.invite_done'));
    }

    // -------------------------------------------------------------------------
    // Shared machinery
    // -------------------------------------------------------------------------

    /**
     * Creates a token and returns the value to put in the link.
     *
     * The link carries "selector.validator"; only the selector and a hash of
     * the validator are stored, so a database dump cannot be turned back into
     * a working link.
     */
    public function issueToken(User $user, string $type, int $lifetimeSeconds): string
    {
        $selector = bin2hex(random_bytes(16));
        $validator = Str::randomToken(32);

        // Any outstanding token of the same type is dropped: two live reset
        // links for one account is one more than anybody needs.
        Database::instance()->table('user_tokens')
            ->where('user_id', '=', $user->id())
            ->where('type', '=', $type)
            ->delete();

        Database::instance()->table('user_tokens')->insert([
            'user_id'    => $user->id(),
            'type'       => $type,
            'selector'   => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $lifetimeSeconds),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $selector . '.' . $validator;
    }

    public function issueInviteToken(User $user): string
    {
        return $this->issueToken($user, 'invite', self::INVITE_DAYS * 86400);
    }

    public static function inviteDays(): int
    {
        return self::INVITE_DAYS;
    }

    /**
     * Looks a token up and checks it, in constant time.
     *
     * @return array<string,mixed>|null
     */
    private function findValidToken(string $token, string $type): ?array
    {
        if (!str_contains($token, '.')) {
            return null;
        }

        [$selector, $validator] = explode('.', $token, 2);

        if (strlen($selector) !== 32 || $validator === '') {
            return null;
        }

        $record = Database::instance()->table('user_tokens')
            ->where('selector', '=', $selector)
            ->where('type', '=', $type)
            ->first();

        if ($record === null) {
            return null;
        }

        if (!hash_equals((string) $record['token_hash'], hash('sha256', $validator))) {
            return null;
        }

        if ($record['used_at'] !== null) {
            return null;
        }

        if (strtotime((string) $record['expires_at'] . ' UTC') < time()) {
            return null;
        }

        return $record;
    }

    /**
     * Sets the password, consumes the token and invalidates every other
     * session the account had.
     */
    private function setPassword(User $user, string $plain, int $tokenId): void
    {
        Database::instance()->transaction(static function () use ($user, $plain, $tokenId): void {
            $user->update([
                'password_hash'   => Password::hash($plain),
                'failed_attempts' => 0,
                'locked_until'    => null,
            ]);

            Database::instance()->table('user_tokens')
                ->where('id', '=', $tokenId)
                ->update(['used_at' => gmdate('Y-m-d H:i:s')]);

            // Changing a password is how someone recovers a compromised
            // account, so every remembered device has to be logged out.
            Database::instance()->table('user_tokens')
                ->where('user_id', '=', $user->id())
                ->where('type', '=', 'remember')
                ->delete();
        });
    }
}
