<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Models\User;
use MTL\Services\InstallService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The state machine behind the web installer.
 *
 * The property that matters most is the lock-out: /install exists only until
 * the first account does, and "is this site installed?" has to flip exactly
 * once and stay flipped. Getting that wrong either bricks a fresh install or
 * leaves an installer open on a live site.
 */
final class InstallTest extends TestCase
{
    protected function setUp(): void
    {
        Database::instance()->statement('DELETE FROM users');
        InstallService::reset(removeLock: true);
    }

    protected function tearDown(): void
    {
        // Leave the memoised answer clean for whatever test class comes next.
        InstallService::reset(removeLock: true);
    }

    private function makeUser(): User
    {
        return User::create([
            'name'              => 'Reiziger',
            'email'             => 'reiziger@example.com',
            'password_hash'     => Password::hash('reis-door-de-wereld-2026'),
            'role'              => User::ROLE_ADMIN,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testAFreshSchemaWithoutAccountsIsNotInstalled(): void
    {
        $this->assertFalse(InstallService::isInstalled());
    }

    /**
     * The self-heal: a site updated by git pull has accounts but no lock file
     * yet, and must not bounce its visitors to a dead installer.
     */
    public function testAnAccountMakesTheSiteInstalledAndWritesTheLock(): void
    {
        $this->makeUser();
        InstallService::reset();

        $this->assertTrue(InstallService::isInstalled());
        $this->assertTrue(is_file(MTL_ROOT . '/storage/cache/installed.lock'), 'the lock should self-heal');
    }

    /**
     * The lock answers without a database. That is the whole point of having
     * one — but it means a stale lock beats an empty users table, which is the
     * documented way to deliberately re-open the installer: delete both.
     */
    public function testTheLockAloneCountsAsInstalled(): void
    {
        InstallService::writeLock();
        InstallService::reset();

        $this->assertTrue(InstallService::isInstalled());
    }

    public function testRepairIsIdempotent(): void
    {
        // First call may create things (a missing deny rule, a directory);
        // from then on there is nothing left to do.
        InstallService::repair();

        $this->assertSame([], InstallService::repair(), 'a second repair should find nothing to fix');
    }

    public function testRepairRecreatesAMissingDenyRule(): void
    {
        $file = MTL_ROOT . '/bin/.htaccess';
        $original = file_get_contents($file);

        unlink($file);

        try {
            $done = InstallService::repair();

            $this->assertTrue(is_file($file), 'bin/.htaccess should be recreated');
            $this->assertContains('Require all denied', (string) file_get_contents($file));
            $this->assertCount(1, $done);
        } finally {
            // Whatever happened, the repository file comes back.
            file_put_contents($file, $original);
        }
    }

    public function testChecksReportTheEnvironment(): void
    {
        $checks = InstallService::checks();

        $this->assertNotEmpty($checks);
        // This suite runs on a working installation, so the required checks
        // hold by construction.
        $this->assertTrue(InstallService::required($checks));
    }

    public function testDatabaseStatusSeesTheMigratedSchema(): void
    {
        $status = InstallService::databaseStatus();

        $this->assertTrue($status['ready']);
        $this->assertSame(0, $status['pending'], 'the test database is always freshly migrated');
        $this->assertGreaterThan(0, $status['applied']);
    }
}
