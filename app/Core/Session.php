<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * Session access with flash-message support.
 *
 * Flash data written during request N is readable during request N+1 and then
 * discarded, which is what makes "redirect back with an error" work without a
 * query string.
 */
final class Session
{
    private const FLASH_NEW = '_flash_new';
    private const FLASH_OLD = '_flash_old';

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;

            if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
                // The console and the test runner get a plain array so session
                // reads and writes work without a real session backend.
                $_SESSION = [];
            }

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        session_name((string) Config::get('session.name', 'mtl_session'));

        session_set_cookie_params([
            'lifetime' => 0,                       // browser session; the app enforces its own idle timeout
            'path'     => Request::basePath() . '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ]);

        session_start();
        self::$started = true;

        self::rotateFlash();
        self::enforceIdleTimeout();
    }

    /**
     * Ages the flash bag one request: what was written last request becomes
     * readable now, and what was readable last request is dropped.
     */
    private static function rotateFlash(): void
    {
        $_SESSION[self::FLASH_OLD] = $_SESSION[self::FLASH_NEW] ?? [];
        $_SESSION[self::FLASH_NEW] = [];
    }

    /**
     * Logs the visitor out after a period of inactivity, independent of the
     * cookie lifetime, so a stolen cookie has a bounded useful life.
     */
    private static function enforceIdleTimeout(): void
    {
        $lifetime = (int) Config::get('session.lifetime', 43200);
        $last = (int) ($_SESSION['_last_activity'] ?? 0);

        if ($last > 0 && (time() - $last) > $lifetime) {
            self::destroy();
            session_start();
            self::rotateFlash();
        }

        $_SESSION['_last_activity'] = time();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Reads and removes a value in one step. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    /** Writes a value that is readable on the next request only. */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_NEW][$key] = $value;
    }

    public static function flashed(string $key, mixed $default = null): mixed
    {
        $bag = $_SESSION[self::FLASH_OLD] ?? [];

        // Support dot access into flashed arrays, e.g. '_old.title'.
        if (str_contains($key, '.')) {
            [$root, $rest] = explode('.', $key, 2);
            $value = $bag[$root] ?? null;

            foreach (explode('.', $rest) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        }

        return $bag[$key] ?? $default;
    }

    /** Carries the current flash bag forward one more request. */
    public static function reflash(): void
    {
        $_SESSION[self::FLASH_NEW] = array_merge(
            $_SESSION[self::FLASH_NEW] ?? [],
            $_SESSION[self::FLASH_OLD] ?? []
        );
    }

    /** Queues a notification for the next page render. */
    public static function notify(string $type, string $message): void
    {
        $queue = $_SESSION[self::FLASH_NEW]['_notifications'] ?? [];
        $queue[] = ['type' => $type, 'message' => $message];
        $_SESSION[self::FLASH_NEW]['_notifications'] = $queue;
    }

    /** @return list<array{type:string,message:string}> */
    public static function notifications(): array
    {
        /** @var list<array{type:string,message:string}> $queue */
        $queue = self::flashed('_notifications', []);

        return $queue;
    }

    /**
     * Issues a new session ID while keeping the data, which must happen on
     * every privilege change so a fixated ID becomes useless.
     */
    public static function regenerate(bool $deleteOld = true): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id($deleteOld);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'mtl_session', '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function id(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : (string) session_id();
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return $_SESSION ?? [];
    }
}
