<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\AttemptStatus;
use MTL\Auth\AuthManager;
use MTL\Auth\Crypto;
use MTL\Auth\Password;
use MTL\Auth\Totp;
use MTL\Core\Database;
use MTL\Core\Request;
use MTL\Models\User;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Signing in, against a real database.
 *
 * The interesting behaviour here is all about what happens when a sign-in does
 * *not* succeed: throttling, lockout, replayed two-factor codes, and what a
 * failure discloses about whether an account exists. None of that can be
 * checked without the tables the counters live in, which is why these are
 * integration tests.
 */
final class AuthTest extends TestCase
{
    private const PASSWORD = 'reis-door-de-wereld-2026';

    private AuthManager $auth;

    protected function setUp(): void
    {
        $db = Database::instance();

        // A clean slate per test: the rate limiter and the lockout counter are
        // stateful by design, so a leftover row from the previous test would
        // change the outcome of the next.
        foreach (['rate_limits', 'user_tokens', 'audit_log', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        $this->auth = new AuthManager();
        AuthManager::swap($this->auth);
    }

    protected function tearDown(): void
    {
        AuthManager::swap(null);
    }

    private function makeUser(array $overrides = []): User
    {
        // The overrides go on the left: array union keeps the left-hand side's
        // keys, so the other way round they would be silently ignored.
        return User::create($overrides + [
            'name'              => 'Reiziger',
            'email'             => 'reiziger@example.com',
            'password_hash'     => Password::hash(self::PASSWORD),
            'role'              => User::ROLE_ADMIN,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * An account with two-factor authentication enrolled.
     *
     * The secret is encrypted at rest, exactly as TwoFactorController stores it.
     * Writing the plain base32 into the column instead makes the challenge fail
     * with "invalid credentials", which looks like a broken verifier rather than
     * a broken fixture.
     *
     * @return array{0: User, 1: string} the account and the plain secret
     */
    private function makeUserWithTwoFactor(): array
    {
        $user = $this->makeUser();
        $secret = Totp::generateSecret();

        $user->update([
            'totp_secret'       => Crypto::encrypt($secret),
            'totp_confirmed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [$user, $secret];
    }

    /**
     * A request as the sign-in form would produce it.
     *
     * Built through capture() from the superglobals rather than through a
     * test-only constructor: the point is to exercise the same path a real
     * request takes, including how the client address is resolved.
     */
    private function request(string $ip = '203.0.113.7'): Request
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['HTTP_USER_AGENT'] = 'MTL test suite';
        $_POST = [];
        $_GET = [];
        $_FILES = [];

        return Request::capture();
    }

    // -------------------------------------------------------------------------

    public function testCorrectPasswordSignsIn(): void
    {
        $user = $this->makeUser();

        $result = $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $this->assertSame(AttemptStatus::Success, $result->status);
        $this->assertTrue($this->auth->check());
        $this->assertSame($user->id(), $this->auth->id());
    }

    public function testWrongPasswordDoesNotSignIn(): void
    {
        $user = $this->makeUser();

        $result = $this->auth->attempt($user->string('email'), 'wrong-password-entirely', false, $this->request());

        $this->assertSame(AttemptStatus::InvalidCredentials, $result->status);
        $this->assertTrue($this->auth->guest());
    }

    /**
     * An unknown address and a wrong password have to be indistinguishable, or
     * the form becomes a way of asking which addresses have accounts.
     */
    public function testAnUnknownAddressReportsTheSameFailureAsAWrongPassword(): void
    {
        $this->makeUser();

        $unknown = $this->auth->attempt('niemand@example.com', self::PASSWORD, false, $this->request());
        $wrong = $this->auth->attempt('reiziger@example.com', 'wrong-password-entirely', false, $this->request());

        $this->assertSame(AttemptStatus::InvalidCredentials, $unknown->status);
        $this->assertSame($wrong->status, $unknown->status);
    }

    public function testTheEmailAddressIsNotCaseSensitive(): void
    {
        $this->makeUser();

        $result = $this->auth->attempt('REIZIGER@Example.COM', self::PASSWORD, false, $this->request());

        $this->assertSame(AttemptStatus::Success, $result->status);
    }

    public function testADisabledAccountCannotSignInEvenWithTheRightPassword(): void
    {
        $user = $this->makeUser();
        $user->update(['status' => 'disabled']);

        $result = $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $this->assertSame(AttemptStatus::Disabled, $result->status);
        $this->assertTrue($this->auth->guest());
    }

    /**
     * Repeated failures against one account lock it, and the lock then applies
     * to the correct password too — otherwise the lock achieves nothing.
     */
    public function testRepeatedFailuresLockTheAccount(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 12; $i++) {
            $this->auth->attempt($user->string('email'), 'wrong-password-entirely', false, $this->request());
        }

        $result = $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $this->assertTrue(
            in_array($result->status, [AttemptStatus::Locked, AttemptStatus::Throttled], true),
            'expected the account to be locked or throttled, got ' . $result->status->value
        );
        $this->assertTrue($this->auth->guest());
    }

    public function testASuccessfulSignInClearsTheFailureCount(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), 'wrong-password-entirely', false, $this->request());
        $this->auth->attempt($user->string('email'), 'wrong-password-entirely', false, $this->request());
        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $fresh = User::find($user->id());

        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->int('failed_attempts'));
        $this->assertFalse($fresh->isLocked());
    }

    // -------------------------------------------------------------------------
    // Two factors
    // -------------------------------------------------------------------------

    public function testAnAccountWithTwoFactorStopsAtTheChallenge(): void
    {
        [$user, $secret] = $this->makeUserWithTwoFactor();

        $result = $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $this->assertSame(AttemptStatus::TwoFactorRequired, $result->status);
        // The password was right, but that alone must not be a session.
        $this->assertTrue($this->auth->guest());
        $this->assertNotNull($this->auth->awaitingTwoFactor());
    }

    public function testTheChallengeCompletesWithAValidCode(): void
    {
        [$user, $secret] = $this->makeUserWithTwoFactor();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $result = $this->auth->completeTwoFactor(Totp::currentCode($secret), $this->request());

        $this->assertSame(AttemptStatus::Success, $result->status, 'got ' . $result->status->value);
        $this->assertTrue($this->auth->check());
    }

    public function testTheChallengeRefusesAWrongCode(): void
    {
        [$user, $secret] = $this->makeUserWithTwoFactor();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $result = $this->auth->completeTwoFactor('000000', $this->request());

        $this->assertNotSame(AttemptStatus::Success, $result->status);
        $this->assertTrue($this->auth->guest());
    }

    /**
     * A code is valid for its whole window, so the step it matched has to be
     * remembered. Without that, a code read over someone's shoulder — or out of
     * a proxy log — can be used again for the next half-minute.
     */
    public function testTheSameCodeCannotBeUsedForASecondSignIn(): void
    {
        [$user, $secret] = $this->makeUserWithTwoFactor();

        $code = Totp::currentCode($secret);

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());
        $first = $this->auth->completeTwoFactor($code, $this->request());
        $this->assertSame(AttemptStatus::Success, $first->status);

        $this->auth->logout();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());
        $second = $this->auth->completeTwoFactor($code, $this->request());

        $this->assertNotSame(AttemptStatus::Success, $second->status, 'the code was accepted twice');
        $this->assertTrue($this->auth->guest());
    }

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    public function testLogoutEndsTheSession(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());
        $this->assertTrue($this->auth->check());

        $this->auth->logout();

        $this->assertTrue($this->auth->guest());
        $this->assertNull($this->auth->id());
    }

    /**
     * A remember-me token is stored split: a selector to look the row up by and
     * a validator that is only ever kept hashed. A stolen database therefore
     * does not hand over working cookies.
     */
    public function testRememberMeStoresAHashedValidator(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), self::PASSWORD, true, $this->request());

        $tokens = Database::instance()->table('user_tokens')
            ->where('user_id', '=', $user->id())
            ->get();

        $this->assertCount(1, $tokens);

        $row = $tokens[0];

        $this->assertNotEmpty($row['selector'] ?? '');
        $this->assertNotEmpty($row['token_hash'] ?? '');
        // A SHA-256 digest in hex, not the value the cookie carries.
        $this->assertSame(64, strlen((string) $row['token_hash']));
    }

    public function testNoTokenIsStoredWithoutRememberMe(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $this->assertCount(0, Database::instance()->table('user_tokens')->where('user_id', '=', $user->id())->get());
    }

    public function testLogoutRemovesTheRememberToken(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), self::PASSWORD, true, $this->request());
        $this->auth->logout();

        $this->assertCount(0, Database::instance()->table('user_tokens')->where('user_id', '=', $user->id())->get());
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function testRolesGrantDifferentPermissions(): void
    {
        $admin = $this->makeUser(['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN]);
        $viewer = $this->makeUser(['email' => 'viewer@example.com', 'role' => User::ROLE_VIEWER]);

        $this->auth->setUser($admin);
        $this->assertTrue($this->auth->can('settings.manage'));

        $this->auth->setUser($viewer);
        $this->assertFalse($this->auth->can('settings.manage'));
        $this->assertFalse($this->auth->can('trip.create'));
    }

    public function testASignedOutVisitorCanDoNothingPrivileged(): void
    {
        $this->auth->setUser(null);

        $this->assertFalse($this->auth->can('settings.manage'));
        $this->assertFalse($this->auth->can('trip.create'));
        $this->assertFalse($this->auth->can('media.upload'));
    }

    public function testTheAuditLogRecordsSignIns(): void
    {
        $user = $this->makeUser();

        $this->auth->attempt($user->string('email'), self::PASSWORD, false, $this->request());

        $entries = Database::instance()->table('audit_log')->where('action', '=', 'user.signed_in')->get();

        $this->assertCount(1, $entries);
    }
}
