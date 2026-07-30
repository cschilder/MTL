<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Services\SearchService;

defined('MTL_APP') || exit;

/**
 * Rebuilds the search index.
 */
final class SearchCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    public function reindex(): int
    {
        $this->out->info('Rebuilding the search index…');

        $count = SearchService::reindex(function (int $done): void {
            $this->out->line('  ' . $done . ' entries…');
        });

        $this->out->success($count . ' entries indexed.');

        return 0;
    }
}
