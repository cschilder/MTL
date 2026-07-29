<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Core\Database;

defined('MTL_APP') || exit;

/**
 * Fixed-window rate limiting backed by the database.
 *
 * Shared hosting spreads requests across PHP processes that share no memory,
 * so an in-process counter would reset constantly. A small table is slower but
 * actually counts.
 */
final class RateLimiter
{
    /**
     * Records one attempt against a bucket and reports whether the caller is
     * still under the limit.
     *
     * @param string $bucket  identifies what is limited, e.g. 'login:ip:1.2.3.4'
     * @param int    $limit   attempts allowed inside the window
     * @param int    $window  window length in seconds
     */
    public static function hit(string $bucket, int $limit, int $window): bool
    {
        $key = self::normalise($bucket);
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + $window);

        $db = Database::instance();

        // A single upsert avoids the read-then-write race that would let two
        // concurrent requests both see "one attempt left".
        //
        // The expiry only moves forward when the previous window has already
        // passed; otherwise a steady stream of attempts would keep pushing the
        // window out and the counter would never reset.
        $db->statement(
            'INSERT INTO ' . self::table() . ' (bucket, hits, expires_at)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at <= ?, 1, hits + 1),
                expires_at = IF(expires_at <= ?, VALUES(expires_at), expires_at)',
            [$key, $expires, $now, $now]
        );

        return self::attempts($key) <= $limit;
    }

    /** Current count for a bucket, ignoring an expired window. */
    public static function attempts(string $bucket): int
    {
        $row = Database::instance()
            ->table('rate_limits')
            ->where('bucket', '=', self::normalise($bucket))
            ->whereRaw('expires_at > UTC_TIMESTAMP()')
            ->first();

        return $row === null ? 0 : (int) $row['hits'];
    }

    public static function tooManyAttempts(string $bucket, int $limit): bool
    {
        return self::attempts($bucket) >= $limit;
    }

    /** Seconds until the window resets; 0 when it already has. */
    public static function availableIn(string $bucket): int
    {
        $row = Database::instance()
            ->table('rate_limits')
            ->where('bucket', '=', self::normalise($bucket))
            ->first();

        if ($row === null) {
            return 0;
        }

        $expires = strtotime((string) $row['expires_at'] . ' UTC');

        return $expires === false ? 0 : max(0, $expires - time());
    }

    /** Clears a bucket, which is what a successful sign-in does. */
    public static function clear(string $bucket): void
    {
        Database::instance()
            ->table('rate_limits')
            ->where('bucket', '=', self::normalise($bucket))
            ->delete();
    }

    /** Removes expired windows. Called by the maintenance task. */
    public static function prune(): int
    {
        return Database::instance()
            ->table('rate_limits')
            ->whereRaw('expires_at < UTC_TIMESTAMP()')
            ->delete();
    }

    /**
     * Buckets are hashed so a long or unusual key (an e-mail address, an IPv6
     * address) always fits the column and never leaks into the table verbatim.
     */
    private static function normalise(string $bucket): string
    {
        $prefix = substr(preg_replace('/[^a-z0-9:._-]/i', '', $bucket) ?? '', 0, 40);

        return $prefix . ':' . substr(hash('sha256', $bucket), 0, 32);
    }

    private static function table(): string
    {
        return Database::quoteIdentifier(Database::instance()->prefix() . 'rate_limits');
    }
}
