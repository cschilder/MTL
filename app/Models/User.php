<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * A person with an account.
 *
 * @property-read int    $id
 * @property-read string $uuid
 * @property-read string $name
 * @property-read string $email
 * @property-read string $role
 * @property-read string $status
 */
final class User extends Model
{
    protected static string $table = 'users';

    /** @var list<string> */
    protected static array $jsonColumns = ['recovery_codes', 'preferences'];

    /** @var list<string> */
    protected static array $dateColumns = [
        'created_at', 'updated_at', 'deleted_at',
        'email_verified_at', 'last_login_at', 'last_seen_at',
        'locked_until', 'totp_confirmed_at',
    ];

    /**
     * Never leaves the application, not through the API and not through a log.
     *
     * @var list<string>
     */
    protected static array $hidden = ['password_hash', 'totp_secret', 'recovery_codes'];

    // -------------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------------

    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_AUTHOR = 'author';
    public const ROLE_VIEWER = 'viewer';

    /** Ordered strongest first; used to compare authority between accounts. */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_AUTHOR, self::ROLE_VIEWER];

    public function role(): string
    {
        return $this->string('role', self::ROLE_VIEWER);
    }

    public function isAdmin(): bool
    {
        return $this->role() === self::ROLE_ADMIN;
    }

    public function isEditor(): bool
    {
        return in_array($this->role(), [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    public function isAuthor(): bool
    {
        return in_array($this->role(), [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_AUTHOR], true);
    }

    /**
     * True when this account outranks $other. Used to stop an editor from
     * modifying an admin, and to stop anyone from demoting themselves out of
     * the only admin seat.
     */
    public function outranks(self $other): bool
    {
        return array_search($this->role(), self::ROLES, true) < array_search($other->role(), self::ROLES, true);
    }

    // -------------------------------------------------------------------------
    // Account state
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->string('status') === 'active' && !$this->isDeleted();
    }

    public function isLocked(): bool
    {
        $until = $this->date('locked_until');

        return $until !== null && $until > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function lockedForSeconds(): int
    {
        $until = $this->date('locked_until');

        if ($until === null) {
            return 0;
        }

        return max(0, $until->getTimestamp() - time());
    }

    public function hasTwoFactor(): bool
    {
        return $this->attribute('totp_confirmed_at') !== null && $this->string('totp_secret') !== '';
    }

    // -------------------------------------------------------------------------
    // Presentation
    // -------------------------------------------------------------------------

    public function displayName(): string
    {
        $name = trim($this->string('name'));

        if ($name !== '') {
            return $name;
        }

        // Fall back to the local part of the address rather than showing a
        // full e-mail on a public page.
        return explode('@', $this->string('email'))[0] ?: 'gebruiker';
    }

    public function initials(): string
    {
        return Str::initials($this->displayName());
    }

    public function timezone(): \DateTimeZone
    {
        $name = $this->string('timezone', 'Europe/Amsterdam');

        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    public function locale(): string
    {
        $locale = $this->string('locale', 'nl');

        return preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : 'nl';
    }

    /**
     * A user interface preference, e.g. preference('editor.mode', 'rich').
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        $value = $this->json('preferences');

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->json('preferences');

        $cursor = &$preferences;
        $segments = explode('.', $key);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
        unset($cursor);

        $this->update(['preferences' => $preferences]);
    }

    // -------------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------------

    public static function findByEmail(string $email): ?self
    {
        $row = self::query()
            ->where('email', '=', strtolower(trim($email)))
            ->whereNull('deleted_at')
            ->first();

        return $row === null ? null : new self($row);
    }

    /** True when no account exists yet, which is what the installer checks. */
    public static function noneExist(): bool
    {
        return !self::query()->exists();
    }

    /** Number of active administrators, used to protect the last one. */
    public static function activeAdminCount(): int
    {
        return self::query()
            ->where('role', '=', self::ROLE_ADMIN)
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->count();
    }
}
