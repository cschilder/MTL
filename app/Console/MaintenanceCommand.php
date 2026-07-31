<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Services\MaintenanceService;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * The scheduled maintenance sweep.
 *
 * On a plan with cron, point it at:
 *   php /path/to/mtl/bin/console.php maintenance
 * once a day. Without cron the cheap parts run opportunistically during normal
 * traffic, so nothing here is strictly required.
 */
final class MaintenanceCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param array<string,string|true> $options
     */
    public function run(array $options): int
    {
        $logDays = (int) ($options['log-days'] ?? 30);
        $auditDays = (int) ($options['audit-days'] ?? 365);

        $this->out->info('Running maintenance…');

        $results = MaintenanceService::runAll($logDays, $auditDays);

        $rows = [];

        foreach ($results as $task => $count) {
            $rows[] = [$task, (string) $count];
        }

        $this->out->table(['Task', 'Affected'], $rows);

        if (($results['orphan_files'] ?? 0) > 0) {
            $this->out->warn(
                $results['orphan_files'] . ' file(s) on disk have no database record. '
                . 'Run `media:verify` to look at them.'
            );
        }

        $health = MaintenanceService::healthReport();

        $this->out->line('');
        $this->out->info(sprintf(
            '%d trips, %d steps, %d media (%s), %s free.',
            $health['trips'],
            $health['steps'],
            $health['media'],
            Str::bytes((int) $health['media_bytes']),
            Str::bytes((int) $health['disk_free'])
        ));

        return 0;
    }
}
