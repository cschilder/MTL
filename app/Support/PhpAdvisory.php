<?php

declare(strict_types=1);

namespace MTL\Support;

defined('MTL_APP') || exit;

/**
 * Whether the PHP this site runs on carries a known critical hole.
 *
 * On shared hosting nobody patches PHP from inside the application: the host
 * installs the update, the customer only picks a branch in a control panel.
 * What the application *can* do is say so — on the first screen after signing
 * in — the moment the running version is older than the first fixed release
 * of its branch. The table below is maintained by hand with each PHP security
 * release that matters; a stale table errs on the side of silence, never of
 * a false alarm, because an unknown branch is treated as fine.
 */
final class PhpAdvisory
{
    /** The advisory the table currently covers. */
    public const ADVISORY = 'PHP2Shell (CVE-2026-109618, CVE-2026-109619)';

    /**
     * First safe release per branch. Branches absent here but older than the
     * oldest listed one are end-of-life: no fix exists for them at all.
     *
     * @var array<string,string>
     */
    private const FIXED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /**
     * Null when the given version is safe. Otherwise what to tell the admin:
     * the running version, its branch, and the first fixed release — or null
     * for `fixed` when the branch will never get one.
     *
     * @return array{version:string,branch:string,fixed:?string,advisory:string}|null
     */
    public static function check(string $version = PHP_VERSION): ?array
    {
        // "8.3.36-1ubuntu1" and "8.3.36RC1": only the numeric core counts.
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return null;
        }

        $branch = $m[1] . '.' . $m[2];
        $core = $m[1] . '.' . $m[2] . '.' . $m[3];

        $oldestFixed = array_key_first(self::FIXED);

        if (isset(self::FIXED[$branch])) {
            if (version_compare($core, self::FIXED[$branch], '>=')) {
                return null;
            }

            return self::report($version, $branch, self::FIXED[$branch]);
        }

        // Older than anything in the table: an end-of-life branch, which the
        // PHP project no longer fixes. Newer than the table: unknown, and an
        // unknown branch is not an alarm.
        if (version_compare($branch, $oldestFixed, '<')) {
            return self::report($version, $branch, null);
        }

        return null;
    }

    /** @return array{version:string,branch:string,fixed:?string,advisory:string} */
    private static function report(string $version, string $branch, ?string $fixed): array
    {
        return [
            'version'  => $version,
            'branch'   => $branch,
            'fixed'    => $fixed,
            'advisory' => self::ADVISORY,
        ];
    }
}
