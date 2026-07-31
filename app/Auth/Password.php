<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Core\Config;

defined('MTL_APP') || exit;

/**
 * Password hashing.
 *
 * Argon2id when the PHP build provides it (it does on PHP 7.3+ with libsodium,
 * which is the case on Strato), bcrypt otherwise. Verification accepts either,
 * and a hash made with the older algorithm is upgraded transparently the next
 * time its owner signs in.
 */
final class Password
{
    public static function hash(string $plain): string
    {
        [$algorithm, $options] = self::algorithm();

        $hash = password_hash($plain, $algorithm, $options);

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Constant-time verification.
     *
     * A missing or empty stored hash still runs a dummy verification so an
     * attacker cannot tell "no such account" from "wrong password" by timing.
     */
    public static function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            self::burnTime();

            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * True when the stored hash uses weaker parameters than the current
     * configuration, e.g. after raising the Argon2 memory cost.
     */
    public static function needsRehash(string $hash): bool
    {
        [$algorithm, $options] = self::algorithm();

        return password_needs_rehash($hash, $algorithm, $options);
    }

    /**
     * @return array{0:string,1:array<string,int>}
     */
    private static function algorithm(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            /** @var array<string,int> $configured */
            $configured = Config::get('security.argon', []);

            return [PASSWORD_ARGON2ID, [
                'memory_cost' => (int) ($configured['memory_cost'] ?? 65536),
                'time_cost'   => (int) ($configured['time_cost'] ?? 4),
                'threads'     => (int) ($configured['threads'] ?? 1),
            ]];
        }

        return [PASSWORD_BCRYPT, ['cost' => 12]];
    }

    /**
     * Spends roughly as long as a real verification would, so that the
     * response time of a sign-in attempt does not reveal whether the address
     * exists.
     */
    public static function burnTime(): void
    {
        static $dummy = null;

        // Hashing once and reusing the result keeps the cost to one verify per
        // request rather than one hash plus one verify.
        $dummy ??= self::hash('mtl-timing-equaliser');

        password_verify('mtl-timing-equaliser', $dummy);
    }
}
