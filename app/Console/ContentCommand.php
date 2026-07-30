<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Markdown\Markdown;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Operations on stored content.
 *
 * The markdown is the source of truth, but the HTML is rendered once on save and
 * stored, so a published page costs no parsing. That is the right trade until the
 * renderer itself changes: a fix to the parser then reaches new writing only, and
 * pages written before it keep the old output. This is how those are brought up to
 * date, without opening and re-saving every report by hand.
 */
final class ContentCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * Re-renders body_html and the excerpt from body_md for every trip and step.
     *
     * @param array<string,string|true> $options --dry-run to report without writing
     */
    public function rerender(array $options): int
    {
        $dryRun = isset($options['dry-run']);

        if ($dryRun) {
            $this->out->info('Dry run: nothing will be written.');
        }

        $rows = [];
        $total = 0;
        $changed = 0;

        foreach ([['trips', Trip::class, 500], ['steps', Step::class, 500]] as [$label, $model, $excerptLength]) {
            $seen = 0;
            $updated = 0;

            /** @var list<Trip|Step> $records */
            $records = $model::fromRows($model::query()->orderBy('id')->get());

            foreach ($records as $record) {
                $seen++;

                $markdown = $record->string('body_md');
                $rendered = Markdown::renderWithContext($markdown);

                if ($rendered['html'] === $record->string('body_html')) {
                    continue;
                }

                $updated++;

                if (!$dryRun) {
                    $record->update([
                        'body_html' => $rendered['html'],
                        'excerpt'   => Str::excerpt($rendered['text'], $excerptLength),
                    ]);
                }
            }

            $rows[] = [$label, (string) $seen, (string) $updated];
            $total += $seen;
            $changed += $updated;
        }

        $this->out->table(['Table', 'Examined', 'Re-rendered'], $rows);

        if ($changed === 0) {
            $this->out->success('All ' . $total . ' record(s) already match the current renderer.');

            return 0;
        }

        $this->out->success(
            ($dryRun ? 'Would re-render ' : 'Re-rendered ') . $changed . ' of ' . $total . ' record(s).'
        );

        if (!$dryRun) {
            $this->out->info('Run `search:reindex` as well if the text of a report changed.');
        }

        return 0;
    }
}
