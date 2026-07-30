<?php

declare(strict_types=1);

namespace MTL\Console;

defined('MTL_APP') || exit;

/**
 * The globe's static geometry.
 *
 * The files themselves are produced by tools/build/build-globe-geometry.mjs,
 * which needs Node and the Natural Earth data — neither of which exists on a
 * web host. This command checks what is present and explains how to rebuild
 * it, rather than pretending it can do the work itself.
 */
final class GlobeCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param array<string,string|true> $options
     */
    public function build(array $options): int
    {
        $directory = MTL_ROOT . '/assets/data';

        $expected = [
            'globe-lines-110m.bin' => 'coastlines and borders, phone resolution',
            'globe-land-110m.png'  => 'land mask, phone resolution',
            'globe-lines-50m.bin'  => 'coastlines and borders, desktop resolution',
            'globe-land-50m.png'   => 'land mask, desktop resolution',
        ];

        $rows = [];
        $missing = 0;

        foreach ($expected as $file => $description) {
            $path = $directory . '/' . $file;

            if (is_file($path)) {
                $rows[] = [$file, \MTL\Support\Str::bytes((int) filesize($path)), $description];
                continue;
            }

            ++$missing;
            $rows[] = [$file, 'missing', $description];
        }

        $this->out->table(['File', 'Size', 'Contents'], $rows);

        if ($missing === 0) {
            $this->out->success('The globe has everything it needs.');

            return 0;
        }

        $this->out->line('');
        $this->out->warn($missing . ' file(s) missing. Rebuild them on a machine with Node:');
        $this->out->line('');
        $this->out->line('    cd tools');
        $this->out->line('    npm install');
        $this->out->line('    npm run globe');
        $this->out->line('');
        $this->out->line('Then upload assets/data/ to the server.');

        return 1;
    }
}
