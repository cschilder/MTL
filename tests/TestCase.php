<?php

declare(strict_types=1);

namespace MTL\Tests;

defined('MTL_APP') || exit;

/**
 * Base class for MTL's tests.
 *
 * There is no PHPUnit: Strato has no Composer, and a test suite that only runs
 * on a machine with a package manager is a test suite that stops being run.
 * This covers what the suite needs — assertions, setup hooks, and a runner that
 * finds methods beginning with `test`.
 */
abstract class TestCase
{
    /** @var list<array{passed:bool,message:string,detail:string}> */
    private array $results = [];

    private string $currentTest = '';

    /** Runs before each test method. */
    protected function setUp(): void
    {
    }

    /** Runs after each test method, even when it failed. */
    protected function tearDown(): void
    {
    }

    /** Runs once before the first test in the class. */
    public static function setUpClass(): void
    {
    }

    /** Runs once after the last test in the class. */
    public static function tearDownClass(): void
    {
    }

    /**
     * @return list<array{test:string,passed:bool,message:string,detail:string,time:float}>
     */
    public function run(?string $filter = null): array
    {
        $methods = array_filter(
            get_class_methods($this),
            static fn (string $method): bool => str_starts_with($method, 'test')
        );

        sort($methods);

        $report = [];

        foreach ($methods as $method) {
            if ($filter !== null && !str_contains(strtolower(static::class . '::' . $method), strtolower($filter))) {
                continue;
            }

            $this->currentTest = $method;
            $this->results = [];

            $started = microtime(true);

            try {
                $this->setUp();
                $this->{$method}();

                if ($this->results === []) {
                    // A test that asserts nothing passes by accident, which is
                    // worse than one that fails.
                    $this->results[] = [
                        'passed'  => false,
                        'message' => 'no assertions were made',
                        'detail'  => '',
                    ];
                }
            } catch (AssertionFailed $e) {
                $this->results[] = ['passed' => false, 'message' => $e->getMessage(), 'detail' => $e->detail];
            } catch (\Throwable $e) {
                $this->results[] = [
                    'passed'  => false,
                    'message' => $e::class . ': ' . $e->getMessage(),
                    'detail'  => $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(),
                ];
            } finally {
                try {
                    $this->tearDown();
                } catch (\Throwable $e) {
                    $this->results[] = ['passed' => false, 'message' => 'tearDown failed: ' . $e->getMessage(), 'detail' => ''];
                }
            }

            $elapsed = (microtime(true) - $started) * 1000;

            $failures = array_values(array_filter($this->results, static fn (array $r): bool => !$r['passed']));

            $report[] = [
                'test'    => static::class . '::' . $method,
                'passed'  => $failures === [],
                'message' => $failures === [] ? count($this->results) . ' assertion(s)' : $failures[0]['message'],
                'detail'  => $failures === [] ? '' : $failures[0]['detail'],
                'time'    => $elapsed,
            ];
        }

        return $report;
    }

    // -------------------------------------------------------------------------
    // Assertions
    // -------------------------------------------------------------------------

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->record($value === true, $message !== '' ? $message : 'expected true', self::describe($value));
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->record($value === false, $message !== '' ? $message : 'expected false', self::describe($value));
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->record(
            $expected === $actual,
            $message !== '' ? $message : 'values are not identical',
            "expected: " . self::describe($expected) . "\nactual:   " . self::describe($actual)
        );
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->record(
            $expected == $actual,
            $message !== '' ? $message : 'values are not equal',
            "expected: " . self::describe($expected) . "\nactual:   " . self::describe($actual)
        );
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->record($expected !== $actual, $message !== '' ? $message : 'values are identical', self::describe($actual));
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->record($value === null, $message !== '' ? $message : 'expected null', self::describe($value));
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->record($value !== null, $message !== '' ? $message : 'expected a value, got null', '');
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->record(
            str_contains($haystack, $needle),
            $message !== '' ? $message : 'substring not found',
            "needle:   " . $needle . "\nhaystack: " . self::truncate($haystack)
        );
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->record(
            !str_contains($haystack, $needle),
            $message !== '' ? $message : 'substring should not be present',
            "needle:   " . $needle . "\nhaystack: " . self::truncate($haystack)
        );
    }

    protected function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->record(
            preg_match($pattern, $subject) === 1,
            $message !== '' ? $message : 'pattern did not match',
            "pattern: " . $pattern . "\nsubject: " . self::truncate($subject)
        );
    }

    protected function assertCount(int $expected, array|\Countable $actual, string $message = ''): void
    {
        $this->record(
            count($actual) === $expected,
            $message !== '' ? $message : 'wrong number of elements',
            'expected ' . $expected . ', got ' . count($actual)
        );
    }

    protected function assertEmpty(mixed $value, string $message = ''): void
    {
        $this->record(empty($value), $message !== '' ? $message : 'expected empty', self::describe($value));
    }

    protected function assertNotEmpty(mixed $value, string $message = ''): void
    {
        $this->record(!empty($value), $message !== '' ? $message : 'expected non-empty', self::describe($value));
    }

    protected function assertGreaterThan(float|int $minimum, float|int $actual, string $message = ''): void
    {
        $this->record($actual > $minimum, $message !== '' ? $message : 'value too small', $actual . ' <= ' . $minimum);
    }

    /**
     * Asserts that $callback throws, optionally of a particular class.
     *
     * @param class-string<\Throwable>|null $expected
     */
    protected function assertThrows(callable $callback, ?string $expected = null, string $message = ''): ?\Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($expected !== null && !($e instanceof $expected)) {
                $this->record(false, $message !== '' ? $message : 'wrong exception type', 'expected ' . $expected . ', got ' . $e::class . ': ' . $e->getMessage());

                return $e;
            }

            $this->record(true, 'threw as expected', '');

            return $e;
        }

        $this->record(false, $message !== '' ? $message : 'expected an exception, none was thrown', '');

        return null;
    }

    /**
     * Compares HTML ignoring differences in whitespace between tags, which the
     * renderer is not expected to control precisely.
     */
    protected function assertHtmlSame(string $expected, string $actual, string $message = ''): void
    {
        $normalise = static function (string $html): string {
            $html = preg_replace('/>\s+</', '><', trim($html)) ?? $html;

            return preg_replace('/\s+/', ' ', $html) ?? $html;
        };

        $this->assertSame($normalise($expected), $normalise($actual), $message);
    }

    private function record(bool $passed, string $message, string $detail): void
    {
        $this->results[] = ['passed' => $passed, 'message' => $message, 'detail' => $detail];

        if (!$passed) {
            // Stop at the first failure inside a test: later assertions in the
            // same method usually cascade from it and add noise.
            throw new AssertionFailed($message, $detail);
        }
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . self::truncate($value) . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_array($value)  => 'array(' . count($value) . ') ' . self::truncate((string) json_encode($value)),
            is_object($value) => $value::class,
            default           => (string) $value,
        };
    }

    private static function truncate(string $value, int $length = 400): string
    {
        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }

    protected function currentTest(): string
    {
        return $this->currentTest;
    }
}
