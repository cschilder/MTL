<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Models\Media;
use MTL\Services\MediaService;

defined('MTL_APP') || exit;

/**
 * Media maintenance from the shell.
 */
final class MediaCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * Regenerates image variants.
     *
     * @param array<string,string|true> $options
     */
    public function rebuild(array $options): int
    {
        $onlyMissing = isset($options['missing']);

        $query = Media::active()->where('kind', '=', 'image');

        if ($onlyMissing) {
            $query->whereGroup(static function ($q): void {
                $q->whereNull('variants')->orWhere('status', '=', 'failed');
            });
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->out->info('Nothing to rebuild.');

            return 0;
        }

        $this->out->info('Rebuilding ' . $total . ' image(s).');

        $done = 0;
        $failed = 0;

        $query->chunk(25, function (array $rows) use (&$done, &$failed, $total): void {
            foreach ($rows as $row) {
                $media = Media::fromRow($row);

                if (!MediaService::rebuildVariants($media)) {
                    ++$failed;
                }

                ++$done;
                $this->out->progress($done, $total, $media->string('original_name'));
            }
        });

        $this->out->line('');

        if ($failed > 0) {
            $this->out->warn($failed . ' file(s) failed; see the media library for the reason.');
        }

        $this->out->success($done . ' image(s) processed.');

        return 0;
    }

    /**
     * Reports records whose file is gone, and files no record points at.
     */
    public function verify(): int
    {
        $missing = [];
        $checked = 0;

        Media::active()->chunk(200, function (array $rows) use (&$missing, &$checked): void {
            foreach ($rows as $row) {
                $media = Media::fromRow($row);
                ++$checked;

                if (!$media->fileExists()) {
                    $missing[] = [(string) $media->id(), $media->string('original_name'), $media->string('path')];
                    continue;
                }

                foreach ($media->json('variants') as $name => $variant) {
                    if (!is_array($variant) || !isset($variant['path'])) {
                        continue;
                    }

                    if (!is_file(storage_path('media/' . $variant['path']))) {
                        $missing[] = [(string) $media->id(), $media->string('original_name'), (string) $name . ': ' . $variant['path']];
                    }
                }
            }
        });

        $this->out->info('Checked ' . $checked . ' record(s).');

        if ($missing === []) {
            $this->out->success('Every file is where the database says it is.');
        } else {
            $this->out->warn(count($missing) . ' missing file(s):');
            $this->out->table(['ID', 'Name', 'Path'], $missing);
            $this->out->line('');
            $this->out->info('Run media:rebuild to regenerate missing variants; a missing original has to be re-uploaded.');
        }

        $orphans = \MTL\Services\MaintenanceService::countOrphanFiles();

        if ($orphans > 0) {
            $this->out->warn($orphans . ' file(s) on disk have no record. They are left alone; remove them by hand if you are sure.');
        }

        return $missing === [] ? 0 : 1;
    }

    /**
     * Empties the bin.
     *
     * @param array<string,string|true> $options
     */
    public function prune(array $options): int
    {
        $days = (int) ($options['days'] ?? 30);

        $candidates = Media::query()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', gmdate('Y-m-d H:i:s', time() - ($days * 86400)))
            ->count();

        if ($candidates === 0) {
            $this->out->info('Nothing older than ' . $days . ' days in the bin.');

            return 0;
        }

        if (!isset($options['force']) && !$this->out->confirm('Permanently delete ' . $candidates . ' file(s)?')) {
            $this->out->info('Cancelled.');

            return 0;
        }

        $removed = MediaService::purgeTrash($days, 1000);

        $this->out->success($removed . ' file(s) permanently deleted.');

        return 0;
    }
}
