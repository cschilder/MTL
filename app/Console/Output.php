<?php

declare(strict_types=1);

namespace MTL\Console;

defined('MTL_APP') || exit;

/**
 * Terminal output with colour, used by every console command.
 *
 * Colour is disabled when the output is not a TTY (a pipe, a CI log, or the
 * management environment capturing the stream), so the text stays readable.
 */
final class Output
{
    private bool $colour;

    public function __construct(private $stream = STDOUT)
    {
        $this->colour = $this->supportsColour();
    }

    private function supportsColour(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return function_exists('stream_isatty') && @stream_isatty($this->stream);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function title(string $text): void
    {
        $this->line($this->colourise($text, '1;36'));
        $this->line($this->colourise(str_repeat('─', mb_strlen($text)), '36'));
    }

    public function success(string $text): void
    {
        $this->line($this->colourise('✓ ', '32') . $text);
    }

    public function info(string $text): void
    {
        $this->line($this->colourise('· ', '34') . $text);
    }

    public function warn(string $text): void
    {
        $this->line($this->colourise('! ' . $text, '33'));
    }

    public function error(string $text): void
    {
        fwrite(STDERR, $this->colourise('✗ ' . $text, '31') . PHP_EOL);
    }

    public function bold(string $text): string
    {
        return $this->colourise($text, '1');
    }

    public function dim(string $text): string
    {
        return $this->colourise($text, '2');
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen((string) $cell));
            }
        }

        $format = static function (array $cells) use ($widths): string {
            $parts = [];
            foreach (array_values($cells) as $index => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$index] ?? 0, ' ', STR_PAD_RIGHT);
            }

            return '  ' . rtrim(implode('  ', $parts));
        };

        $this->line($this->bold($format($headers)));
        $this->line($this->dim('  ' . implode('  ', array_map(static fn (int $w): string => str_repeat('─', $w), $widths))));

        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    /**
     * Reads a line from stdin. Returns $default when the input is not
     * interactive, so a scripted run does not hang waiting for a human.
     */
    public function ask(string $question, string $default = ''): string
    {
        $suffix = $default === '' ? '' : ' [' . $default . ']';
        fwrite($this->stream, $this->colourise('? ', '36') . $question . $suffix . ': ');

        if (!$this->isInteractive()) {
            $this->line($default);

            return $default;
        }

        $answer = fgets(STDIN);

        if ($answer === false) {
            return $default;
        }

        $answer = trim($answer);

        return $answer === '' ? $default : $answer;
    }

    /** Reads a line without echoing it, for passwords. */
    public function askHidden(string $question): string
    {
        fwrite($this->stream, $this->colourise('? ', '36') . $question . ': ');

        if (!$this->isInteractive()) {
            $this->line('');

            return '';
        }

        // stty is available on the Linux hosts this runs on; if it is not,
        // fall back to a visible prompt rather than failing.
        $hasStty = @shell_exec('command -v stty') !== null;

        if ($hasStty) {
            @shell_exec('stty -echo');
        }

        $answer = fgets(STDIN);

        if ($hasStty) {
            @shell_exec('stty echo');
        }

        $this->line('');

        return $answer === false ? '' : trim($answer);
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $answer = strtolower($this->ask($question . ' (y/n)', $default ? 'y' : 'n'));

        return in_array($answer, ['y', 'yes', 'j', 'ja'], true);
    }

    public function isInteractive(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    /**
     * Draws a progress bar that overwrites itself, degrading to periodic
     * milestones when the output is a log file.
     */
    public function progress(int $done, int $total, string $label = ''): void
    {
        if ($total <= 0) {
            return;
        }

        $percent = (int) round(($done / $total) * 100);

        if (!$this->colour) {
            // Non-TTY: print every 10% instead of thousands of lines.
            if ($done === $total || $percent % 10 === 0) {
                $this->line(sprintf('  %3d%% (%d/%d) %s', $percent, $done, $total, $label));
            }

            return;
        }

        $width = 30;
        $filled = (int) round(($done / $total) * $width);

        fwrite($this->stream, sprintf(
            "\r  [%s%s] %3d%% %s",
            str_repeat('█', $filled),
            str_repeat('░', $width - $filled),
            $percent,
            mb_substr($label, 0, 40)
        ));

        if ($done >= $total) {
            fwrite($this->stream, PHP_EOL);
        }
    }

    private function colourise(string $text, string $code): string
    {
        return $this->colour ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
