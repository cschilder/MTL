<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Auth\RateLimiter;
use MTL\Core\Database;
use MTL\Core\Logger;

defined('MTL_APP') || exit;

/**
 * Housekeeping.
 *
 * Not every Strato plan offers cron, so the cheap tasks ride along with normal
 * traffic: roughly one request in two hundred runs them after the response has
 * already been sent. The expensive tasks are only run from the console or from
 * the management environment, where someone is waiting for them.
 */
final class MaintenanceService
{
    /**
     * Fast, idempotent cleanups. Must stay well under a second.
     *
     * @return array<string,int>
     */
    public static function runOpportunisticTasks(): array
    {
        $last = SettingsService::get('maintenance.last_run', '');

        // At most once an hour, however much traffic the site gets.
        if (is_string($last) && $last !== '' && (time() - (int) strtotime($last . ' UTC')) < 3600) {
            return [];
        }

        SettingsService::set('maintenance.last_run', gmdate('Y-m-d H:i:s'), 'string', 'maintenance', false);

        $results = [
            'rate_limits'      => RateLimiter::prune(),
            'expired_tokens'   => self::pruneExpiredTokens(),
            'upload_sessions'  => self::pruneExpiredUploads(),
            'temp_files'       => self::pruneTempFiles(),
        ];

        Logger::instance()->debug('Opportunistic maintenance ran', $results);

        return $results;
    }

    /**
     * The full sweep, including the parts that touch every row.
     *
     * @return array<string,int>
     */
    public static function runAll(int $logDays = 30, int $auditDays = 365): array
    {
        return [
            'rate_limits'     => RateLimiter::prune(),
            'expired_tokens'  => self::pruneExpiredTokens(),
            'upload_sessions' => self::pruneExpiredUploads(),
            'temp_files'      => self::pruneTempFiles(),
            'log_files'       => Logger::instance()->prune($logDays),
            'audit_entries'   => AuditService::prune($auditDays),
            'counters'        => self::recalculateCounters(),
            'orphan_files'    => self::countOrphanFiles(),
        ];
    }

    /** Password resets, invitations and remember-me tokens past their date. */
    public static function pruneExpiredTokens(): int
    {
        return Database::instance()
            ->table('user_tokens')
            ->whereRaw('expires_at < UTC_TIMESTAMP()')
            ->delete();
    }

    /**
     * Abandoned chunked uploads: the row goes, and so does the partial file.
     */
    public static function pruneExpiredUploads(): int
    {
        $db = Database::instance();

        $expired = $db->table('upload_sessions')
            ->whereRaw('expires_at < UTC_TIMESTAMP()')
            ->where('status', '!=', 'complete')
            ->limit(200)
            ->get();

        $removed = 0;

        foreach ($expired as $row) {
            $path = storage_path('tmp/' . basename((string) $row['temp_path']));

            if (is_file($path)) {
                @unlink($path);
            }

            $db->table('upload_sessions')->where('id', '=', (int) $row['id'])->delete();
            ++$removed;
        }

        return $removed;
    }

    /**
     * Files in storage/tmp with no upload session behind them, left over from
     * a request that died between writing the file and committing the row.
     */
    public static function pruneTempFiles(int $olderThanSeconds = 86400): int
    {
        $directory = storage_path('tmp');

        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - $olderThanSeconds;
        $removed = 0;

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!is_file($file) || basename($file) === '.gitkeep') {
                continue;
            }

            if (filemtime($file) < $cutoff && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Recomputes the denormalised counters on trips, steps and albums.
     *
     * They are maintained incrementally on write; this repairs the drift that
     * an interrupted request can leave behind.
     *
     * @return int number of rows corrected
     */
    public static function recalculateCounters(): int
    {
        $db = Database::instance();
        $prefix = $db->prefix();
        $corrected = 0;

        // Steps per trip, and media per step, in one statement each rather
        // than a query per row.
        $corrected += $db->statement(
            "UPDATE `{$prefix}trips` t
             SET t.step_count = (
                 SELECT COUNT(*) FROM `{$prefix}steps` s
                 WHERE s.trip_id = t.id AND s.deleted_at IS NULL
             )
             WHERE t.step_count <> (
                 SELECT COUNT(*) FROM `{$prefix}steps` s
                 WHERE s.trip_id = t.id AND s.deleted_at IS NULL
             )"
        );

        $corrected += $db->statement(
            "UPDATE `{$prefix}steps` s
             SET s.media_count = (
                 SELECT COUNT(*) FROM `{$prefix}step_media` sm
                 JOIN `{$prefix}media` m ON m.id = sm.media_id AND m.deleted_at IS NULL
                 WHERE sm.step_id = s.id
             )
             WHERE s.deleted_at IS NULL"
        );

        $corrected += $db->statement(
            "UPDATE `{$prefix}albums` a
             SET a.media_count = (
                 SELECT COUNT(*) FROM `{$prefix}album_media` am
                 JOIN `{$prefix}media` m ON m.id = am.media_id AND m.deleted_at IS NULL
                 WHERE am.album_id = a.id
             )
             WHERE a.deleted_at IS NULL"
        );

        $corrected += $db->statement(
            "UPDATE `{$prefix}trips` t
             SET t.media_count = (
                 SELECT COUNT(DISTINCT sm.media_id)
                 FROM `{$prefix}steps` s
                 JOIN `{$prefix}step_media` sm ON sm.step_id = s.id
                 JOIN `{$prefix}media` m ON m.id = sm.media_id AND m.deleted_at IS NULL
                 WHERE s.trip_id = t.id AND s.deleted_at IS NULL
             )
             WHERE t.deleted_at IS NULL"
        );

        // Tag usage counts.
        $corrected += $db->statement(
            "UPDATE `{$prefix}tags` tg
             SET tg.usage_count = (
                 SELECT COUNT(*) FROM `{$prefix}taggables` tb WHERE tb.tag_id = tg.id
             )"
        );

        return $corrected;
    }

    /**
     * Counts files under storage/media that no media row points at.
     *
     * Reporting rather than deleting: an orphan is usually a symptom worth
     * looking at, and deleting a file the database has simply not caught up
     * with yet would be worse than leaving it.
     */
    public static function countOrphanFiles(): int
    {
        $root = storage_path('media');

        if (!is_dir($root)) {
            return 0;
        }

        $known = [];

        Database::instance()->table('media')->select('path', 'variants')->chunk(500, static function (array $rows) use (&$known): void {
            foreach ($rows as $row) {
                $known[(string) $row['path']] = true;

                $variants = json_decode((string) ($row['variants'] ?? ''), true);
                if (is_array($variants)) {
                    foreach ($variants as $variant) {
                        if (is_array($variant) && isset($variant['path'])) {
                            $known[(string) $variant['path']] = true;
                        }
                    }
                }
            }
        });

        $orphans = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (!isset($known[$relative])) {
                ++$orphans;
            }
        }

        return $orphans;
    }

    /**
     * A snapshot for the management dashboard.
     *
     * @return array<string,int|string>
     */
    public static function healthReport(): array
    {
        $db = Database::instance();

        $mediaBytes = (int) $db->table('media')->whereNull('deleted_at')->sum('storage_bytes');

        return [
            'php_version'      => PHP_VERSION,
            'database_version' => $db->serverVersion(),
            'users'            => $db->table('users')->whereNull('deleted_at')->count(),
            'trips'            => $db->table('trips')->whereNull('deleted_at')->count(),
            'steps'            => $db->table('steps')->whereNull('deleted_at')->count(),
            'albums'           => $db->table('albums')->whereNull('deleted_at')->count(),
            'media'            => $db->table('media')->whereNull('deleted_at')->count(),
            'media_bytes'      => $mediaBytes,
            'trashed_media'    => $db->table('media')->whereNotNull('deleted_at')->count(),
            'pending_uploads'  => $db->table('upload_sessions')->where('status', '!=', 'complete')->count(),
            'disk_free'        => (int) (@disk_free_space(storage_path()) ?: 0),
        ];
    }
}
