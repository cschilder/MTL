<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Line-based file logger with daily rotation.
 *
 * Shared hosting gives no access to the system log, so everything lands in
 * storage/logs/mtl-YYYY-MM-DD.log and the admin log viewer reads it back.
 */
final class Logger
{
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;

    private const LABELS = [
        self::DEBUG    => 'DEBUG',
        self::INFO     => 'INFO',
        self::NOTICE   => 'NOTICE',
        self::WARNING  => 'WARNING',
        self::ERROR    => 'ERROR',
        self::CRITICAL => 'CRITICAL',
    ];

    private static ?self $instance = null;

    private int $minimumLevel;

    private string $directory;

    private function __construct()
    {
        $this->directory = MTL_ROOT . '/storage/logs';
        $this->minimumLevel = Config::isDebug() ? self::DEBUG : self::INFO;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(int $level, string $message, array $context = []): void
    {
        if ($level < $this->minimumLevel) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            self::LABELS[$level] ?? 'LOG',
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : ' ' . $this->encodeContext($context),
        );

        $this->write($line);
    }

    /** @param array<string,mixed> $context */
    private function encodeContext(array $context): string
    {
        // Never let a password or token reach the log file, even if a caller
        // passes the whole request payload by accident.
        $redacted = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'password') || str_contains($lower, 'token') || str_contains($lower, 'secret')) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = is_scalar($value) || $value === null ? $value : self::describe($value);
        }

        return (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return $value::class;
        }

        return gettype($value);
    }

    private function write(string $line): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            // Logging must never be the reason a request fails.
            error_log(rtrim($line));

            return;
        }

        $file = $this->directory . '/mtl-' . gmdate('Y-m-d') . '.log';

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /**
     * Most recent log lines, newest first, for the admin log viewer.
     *
     * @return list<string>
     */
    public function tail(int $lines = 200, ?string $date = null): array
    {
        $file = $this->directory . '/mtl-' . ($date ?? gmdate('Y-m-d')) . '.log';

        if (!is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        // Read backwards in blocks so a multi-megabyte log does not have to be
        // loaded to show the last screenful.
        $buffer = '';
        $chunk = 8192;
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min($chunk, $position);
            $position -= $read;
            fseek($handle, $position, SEEK_SET);
            $buffer = (string) fread($handle, $read) . $buffer;
        }

        fclose($handle);

        $all = array_values(array_filter(explode("\n", $buffer), static fn (string $l): bool => trim($l) !== ''));

        return array_reverse(array_slice($all, -$lines));
    }

    /** @return list<string> available log dates, newest first */
    public function availableDates(): array
    {
        $files = glob($this->directory . '/mtl-*.log') ?: [];

        $dates = [];
        foreach ($files as $file) {
            if (preg_match('/mtl-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) === 1) {
                $dates[] = $m[1];
            }
        }

        rsort($dates);

        return $dates;
    }

    /** Deletes log files older than $days. Called by the maintenance task. */
    public function prune(int $days = 30): int
    {
        $cutoff = time() - ($days * 86400);
        $removed = 0;

        foreach (glob($this->directory . '/mtl-*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
