<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\AttemptStatus;
use MTL\Auth\AuthManager;
use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Core\Request;
use MTL\Models\User;
use MTL\Services\RegistrationService;
use MTL\Services\SettingsService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Self-registration with approval.
 *
 * The two promises pinned here: nobody signs in before an administrator says
 * so, and a self-registered stranger never starts with power — whatever the
 * default-role setting claims.
 */
final class RegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['audit_log', 'rate_limits', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        SettingsService::set('registration.open', true);
        SettingsService::flush();
    }

    protected function tearDown(): void
    {
        SettingsService::set('registration.open', false);
        SettingsService::flush();
        AuthManager::swap(null);
    }

    private function request(): Request
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['HTTP_USER_AGENT'] = 'MTL test suite';
        $_POST = [];
        $_GET = [];
        $_FILES = [];

        return Request::capture();
    }

    private function admin(): User
    {
        return User::create([
            'name'          => 'Beheerder',
            'email'         => 'admin@example.com',
            'password_hash' => Password::hash('reis-door-de-wereld-2026'),
            'role'          => 'admin',
            'status'        => 'active',
        ]);
    }

    public function testARequestLandsAsPendingAndCannotSignIn(): void
    {
        $user = RegistrationService::register([
            'name'     => 'Nieuwe reiziger',
            'email'    => 'Nieuw@Example.com',
            'password' => 'een-lang-genoeg-wachtwoord',
        ]);

        $this->assertSame('pending', $user->string('status'));
        // Addresses are stored lowercased, so tomorrow's duplicate check works.
        $this->assertSame('nieuw@example.com', $user->string('email'));

        $result = (new AuthManager())->attempt('nieuw@example.com', 'een-lang-genoeg-wachtwoord', false, $this->request());

        $this->assertSame(AttemptStatus::NotActivated, $result->status);
    }

    public function testApprovalActivatesAndSignInWorks(): void
    {
        $admin = $this->admin();

        $user = RegistrationService::register([
            'name'     => 'Nieuwe reiziger',
            'email'    => 'nieuw@example.com',
            'password' => 'een-lang-genoeg-wachtwoord',
        ]);

        RegistrationService::approve($user, $admin);

        $this->assertSame('active', $user->string('status'));

        $result = (new AuthManager())->attempt('nieuw@example.com', 'een-lang-genoeg-wachtwoord', false, $this->request());

        $this->assertTrue($result->succeeded());
    }

    public function testClosedRegistrationRefuses(): void
    {
        SettingsService::set('registration.open', false);
        SettingsService::flush();

        $this->assertThrows(static fn () => RegistrationService::register([
            'name'     => 'Te laat',
            'email'    => 'dicht@example.com',
            'password' => 'een-lang-genoeg-wachtwoord',
        ]), \RuntimeException::class);
    }

    public function testADuplicateAddressRefuses(): void
    {
        $this->admin();

        $this->assertThrows(static fn () => RegistrationService::register([
            'name'     => 'Dubbel',
            'email'    => 'ADMIN@example.com',
            'password' => 'een-lang-genoeg-wachtwoord',
        ]), \RuntimeException::class);
    }

    public function testTheDefaultRoleCanNeverBeAdmin(): void
    {
        SettingsService::set('registration.default_role', 'admin');
        SettingsService::flush();

        $user = RegistrationService::register([
            'name'     => 'Sluwe bezoeker',
            'email'    => 'sluw@example.com',
            'password' => 'een-lang-genoeg-wachtwoord',
        ]);

        $this->assertSame('viewer', $user->string('role'));

        SettingsService::set('registration.default_role', 'viewer');
        SettingsService::flush();
    }
}
