<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Core\Config;
use MTL\Core\Csrf;
use MTL\Core\Database;
use MTL\Core\Request;
use MTL\Core\Session;
use MTL\Models\User;
use MTL\Services\AuditService;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Signing in, signing out, and answering "who is this and what may they do?".
 */
final class AuthManager
{
    private const SESSION_KEY = '_auth_user';
    private const SESSION_2FA = '_auth_pending_2fa';
    private const REMEMBER_COOKIE = 'mtl_remember';

    private static ?self $instance = null;

    private ?User $user = null;

    private bool $resolved = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Replaces the shared instance. Used by the test runner. */
    public static function swap(?self $instance): void
    {
        self::$instance = $instance;
    }

    // -------------------------------------------------------------------------
    // Current user
    // -------------------------------------------------------------------------

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $id = Session::get(self::SESSION_KEY);

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $user = User::find((int) $id);

            if ($user !== null && $user->isActive()) {
                return $this->user = $this->touch($user);
            }

            // The account was disabled or deleted while its session was live.
            Session::forget(self::SESSION_KEY);
        }

        return $this->user = $this->resolveFromRememberCookie();
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?int
    {
        return $this->user()?->id();
    }

    public function can(string $permission, mixed $subject = null): bool
    {
        return Gate::allows($this->user(), $permission, $subject);
    }

    /** Sets the current user directly. Used by the installer and the tests. */
    public function setUser(?User $user): void
    {
        $this->user = $user;
        $this->resolved = true;
    }

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    public function attempt(string $email, string $password, bool $remember, Request $request): AttemptResult
    {
        $email = strtolower(trim($email));
        $ip = $request->ip();

        $limit = (int) Config::get('security.login_attempts', 8);
        $window = (int) Config::get('security.login_window', 900);

        // Two buckets: one per address (stops a slow spread across accounts)
        // and one per account (stops a focused attack on one account from any
        // number of addresses).
        $ipBucket = 'login:ip:' . $ip;
        $accountBucket = 'login:account:' . $email;

        if (RateLimiter::tooManyAttempts($ipBucket, $limit * 3)) {
            $this->recordFailure($email, $ip, 'ip_throttled');

            return AttemptResult::throttled(RateLimiter::availableIn($ipBucket));
        }

        RateLimiter::hit($ipBucket, $limit * 3, $window);

        $user = User::findByEmail($email);

        if ($user === null) {
            // Spend the same time as a real verification would, so response
            // timing does not reveal whether the address exists.
            Password::burnTime();
            RateLimiter::hit($accountBucket, $limit, $window);
            $this->recordFailure($email, $ip, 'unknown_account');

            return AttemptResult::invalid();
        }

        if ($user->isLocked()) {
            $this->recordFailure($email, $ip, 'locked');

            return AttemptResult::locked($user->lockedForSeconds());
        }

        if ($user->string('status') === 'disabled') {
            $this->recordFailure($email, $ip, 'disabled');

            return AttemptResult::disabled();
        }

        if (!Password::verify($password, $user->string('password_hash'))) {
            RateLimiter::hit($accountBucket, $limit, $window);
            $this->registerFailedAttempt($user);
            $this->recordFailure($email, $ip, 'wrong_password');

            return AttemptResult::invalid();
        }

        // 'invited' (admin created it, person has not claimed it) and
        // 'pending' (person requested it, admin has not approved it) both
        // stop here — anything that is not 'active' does, so a status added
        // later can never fall through into a session by omission.
        if ($user->string('status') !== 'active') {
            return AttemptResult::notActivated($user);
        }

        // Correct password: the counters for this account can go.
        RateLimiter::clear($accountBucket);
        $user->update(['failed_attempts' => 0, 'locked_until' => null]);

        // Upgrade a hash made with older parameters now that the plaintext is
        // available and already verified.
        if (Password::needsRehash($user->string('password_hash'))) {
            $user->update(['password_hash' => Password::hash($password)]);
        }

        if ($user->hasTwoFactor()) {
            // Park the identity in the session; the second factor completes it.
            Session::put(self::SESSION_2FA, [
                'user_id'  => $user->id(),
                'remember' => $remember,
                'at'       => time(),
            ]);

            return AttemptResult::twoFactorRequired($user);
        }

        $this->login($user, $remember, $request);

        return AttemptResult::success($user);
    }

    /**
     * Second step of a two-factor sign-in.
     *
     * Accepts a TOTP code or one of the stored recovery codes.
     */
    public function completeTwoFactor(string $code, Request $request): AttemptResult
    {
        $pending = Session::get(self::SESSION_2FA);

        if (!is_array($pending) || !isset($pending['user_id'])) {
            return AttemptResult::invalid();
        }

        // The window between password and code is deliberately short.
        if (time() - (int) ($pending['at'] ?? 0) > 300) {
            Session::forget(self::SESSION_2FA);

            return AttemptResult::invalid();
        }

        $user = User::find((int) $pending['user_id']);

        if ($user === null || !$user->isActive()) {
            Session::forget(self::SESSION_2FA);

            return AttemptResult::invalid();
        }

        $bucket = '2fa:user:' . $user->id();

        if (RateLimiter::tooManyAttempts($bucket, 10)) {
            return AttemptResult::throttled(RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket, 10, 900);

        $secret = Crypto::decrypt($user->string('totp_secret'));

        if ($secret !== null) {
            $lastStep = (int) $user->preference('totp.last_step', 0);
            $step = Totp::verify($secret, $code, null, $lastStep);

            if ($step !== null) {
                // Remember the step so the same code cannot be replayed inside
                // its acceptance window.
                $user->setPreference('totp.last_step', $step);

                RateLimiter::clear($bucket);
                Session::forget(self::SESSION_2FA);
                $this->login($user, (bool) ($pending['remember'] ?? false), $request);

                return AttemptResult::success($user);
            }
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            RateLimiter::clear($bucket);
            Session::forget(self::SESSION_2FA);
            $this->login($user, false, $request);

            AuditService::log('user.recovery_code_used', $user, ['user_id' => $user->id()]);

            return AttemptResult::success($user);
        }

        return AttemptResult::invalid();
    }

    public function awaitingTwoFactor(): ?User
    {
        $pending = Session::get(self::SESSION_2FA);

        if (!is_array($pending) || !isset($pending['user_id'])) {
            return null;
        }

        return User::find((int) $pending['user_id']);
    }

    /**
     * Establishes the signed-in session.
     */
    public function login(User $user, bool $remember, ?Request $request = null): void
    {
        // A new session ID at the moment privileges change: a fixated ID
        // captured before sign-in is worthless afterwards.
        Session::regenerate();
        Csrf::rotate();

        Session::put(self::SESSION_KEY, $user->id());
        Session::forget(self::SESSION_2FA);

        $this->setUser($user);

        $user->update([
            'last_login_at'   => gmdate('Y-m-d H:i:s'),
            'last_login_ip'   => $request?->ip() ?? '',
            'last_seen_at'    => gmdate('Y-m-d H:i:s'),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ]);

        if ($remember) {
            $this->issueRememberToken($user, $request);
        }

        AuditService::log('user.signed_in', $user, ['remember' => $remember]);
    }

    public function logout(): void
    {
        $user = $this->user();

        if ($user !== null) {
            $this->revokeRememberTokens($user);
            AuditService::log('user.signed_out', $user);
        }

        $this->clearRememberCookie();

        Session::destroy();

        $this->user = null;
        $this->resolved = true;
    }

    // -------------------------------------------------------------------------
    // Remember-me tokens
    // -------------------------------------------------------------------------

    /**
     * Issues a persistent sign-in cookie.
     *
     * The cookie holds "selector:validator". Only the selector and a hash of
     * the validator are stored, so a leaked database cannot be turned back
     * into a working cookie.
     */
    private function issueRememberToken(User $user, ?Request $request): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = Str::randomToken(32);

        $days = (int) Config::get('session.remember_days', 30);

        Database::instance()->table('user_tokens')->insert([
            'user_id'    => $user->id(),
            'type'       => 'remember',
            'selector'   => $selector,
            'token_hash' => hash('sha256', $validator),
            'ip'         => $request?->ip() ?? '',
            'user_agent' => $request?->userAgent() ?? '',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($days * 86400)),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + ($days * 86400),
            'path'     => Request::basePath() . '/',
            'secure'   => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function resolveFromRememberCookie(): ?User
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';

        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        if (strlen($selector) !== 32 || $validator === '') {
            return null;
        }

        $row = Database::instance()
            ->table('user_tokens')
            ->where('selector', '=', $selector)
            ->where('type', '=', 'remember')
            ->first();

        if ($row === null) {
            $this->clearRememberCookie();

            return null;
        }

        $expired = strtotime((string) $row['expires_at'] . ' UTC') < time();

        if ($expired || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            // A selector that exists with the wrong validator means the cookie
            // was copied or guessed. Drop every token for the account so the
            // real owner is not left sharing a session.
            if (!$expired) {
                $this->revokeRememberTokens(User::find((int) $row['user_id']));
                logger()->warning('Remember-me validator mismatch', ['user_id' => $row['user_id']]);
            } else {
                Database::instance()->table('user_tokens')->where('id', '=', $row['id'])->delete();
            }

            $this->clearRememberCookie();

            return null;
        }

        $user = User::find((int) $row['user_id']);

        if ($user === null || !$user->isActive()) {
            $this->clearRememberCookie();

            return null;
        }

        // The cookie proves identity but does not confer a fresh session's
        // authority: the session is established, and sensitive screens ask for
        // the password again through RequirePasswordMiddleware.
        Session::put(self::SESSION_KEY, $user->id());
        Session::put('_auth_via_remember', true);

        return $this->touch($user);
    }

    private function revokeRememberTokens(?User $user): void
    {
        if ($user === null) {
            return;
        }

        Database::instance()
            ->table('user_tokens')
            ->where('user_id', '=', $user->id())
            ->where('type', '=', 'remember')
            ->delete();
    }

    private function clearRememberCookie(): void
    {
        // MTL_CONSOLE as well as the SAPI: Strato's shell runs the CGI
        // binary, and a console run must never try to send a cookie.
        if (PHP_SAPI === 'cli' || defined('MTL_CONSOLE') || headers_sent()) {
            return;
        }

        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => Request::basePath() . '/',
            'secure'   => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** True when this session came from a cookie rather than a fresh sign-in. */
    public function viaRemember(): bool
    {
        return (bool) Session::get('_auth_via_remember', false);
    }

    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    /**
     * Counts a failed attempt and locks the account once the limit is reached.
     */
    private function registerFailedAttempt(User $user): void
    {
        $attempts = $user->int('failed_attempts') + 1;
        $limit = (int) Config::get('security.login_attempts', 8);

        $values = ['failed_attempts' => $attempts];

        if ($attempts >= $limit) {
            $lockout = (int) Config::get('security.lockout', 900);

            // Each further round of failures locks the account for longer, up
            // to an hour, so a persistent attacker slows down while a genuine
            // user who mistypes twice is barely affected.
            $multiplier = min(4, (int) floor($attempts / $limit));

            $values['locked_until'] = gmdate('Y-m-d H:i:s', time() + ($lockout * $multiplier));
        }

        $user->update($values);
    }

    private function recordFailure(string $email, string $ip, string $reason): void
    {
        logger()->notice('Sign-in failed', [
            'email'  => $email,
            'ip'     => $ip,
            'reason' => $reason,
        ]);
    }

    /**
     * Checks a recovery code and removes it if it matched.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if ($normalised === '') {
            return false;
        }

        $stored = $user->json('recovery_codes');
        $remaining = [];
        $matched = false;

        foreach ($stored as $entry) {
            $hash = is_string($entry) ? $entry : '';

            if (!$matched && $hash !== '' && hash_equals($hash, hash('sha256', $normalised))) {
                $matched = true;
                continue;
            }

            $remaining[] = $hash;
        }

        if ($matched) {
            $user->update(['recovery_codes' => $remaining]);
        }

        return $matched;
    }

    /**
     * Updates last_seen_at at most once every five minutes, so a browsing
     * session does not produce one write per page view.
     */
    private function touch(User $user): User
    {
        $lastSeen = $user->date('last_seen_at');

        if ($lastSeen === null || (time() - $lastSeen->getTimestamp()) > 300) {
            $user->update(['last_seen_at' => gmdate('Y-m-d H:i:s')]);
        }

        return $user;
    }
}
