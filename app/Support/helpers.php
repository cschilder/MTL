<?php
/**
 * Procedural helpers available everywhere, including inside view templates
 * where a short name matters for readability.
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

if (!function_exists('env')) {
    /**
     * Reads an environment variable, falling back to $default.
     *
     * The strings "true", "false", "null" and "" are converted to their typed
     * equivalents so a value set in the shell behaves like one written in PHP.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Dot-notation access to config/config.php, e.g. config('database.host').
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return MTL\Core\Config::all();
        }

        return MTL\Core\Config::get($key, $default);
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escapes a value for output in a template.
     *
     * Everything printed into a view goes through this. Markdown that has
     * already been rendered and sanitised is the one exception and is echoed
     * with a comment marking it as such.
     */
    function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        } elseif (!is_string($value)) {
            $value = is_scalar($value) ? (string) $value : '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Builds an application URL from a path, honouring installations that live
     * in a subdirectory rather than at the domain root.
     *
     * @param array<string,scalar|null> $query
     */
    function url(string $path = '/', array $query = []): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $prefix = MTL\Core\Request::basePath();

        $path = '/' . ltrim($path, '/');
        $full = $base . $prefix . ($path === '/' ? '' : $path);

        if ($query !== []) {
            $filtered = array_filter($query, static fn ($v) => $v !== null && $v !== '');
            if ($filtered !== []) {
                $full .= '?' . http_build_query($filtered);
            }
        }

        return $full === '' ? '/' : $full;
    }
}

if (!function_exists('path')) {
    /**
     * Same as url() but returns a root-relative path, which is what belongs in
     * href/src attributes so the page keeps working behind a proxy.
     *
     * @param array<string,scalar|null> $query
     */
    function path(string $path = '/', array $query = []): string
    {
        $prefix = MTL\Core\Request::basePath();
        $path = '/' . ltrim($path, '/');
        $full = $prefix . ($path === '/' ? '/' : $path);

        if ($query !== []) {
            $filtered = array_filter($query, static fn ($v) => $v !== null && $v !== '');
            if ($filtered !== []) {
                $full .= '?' . http_build_query($filtered);
            }
        }

        return $full;
    }
}

if (!function_exists('asset')) {
    /**
     * URL for a file under assets/, with a content hash appended so a deploy
     * invalidates the immutable cache set in .htaccess.
     */
    function asset(string $file): string
    {
        return MTL\Core\Assets::url($file);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return MTL_ROOT . '/storage' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return MTL_ROOT . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('db')) {
    function db(): MTL\Core\Database
    {
        return MTL\Core\Database::instance();
    }
}

if (!function_exists('auth')) {
    function auth(): MTL\Auth\AuthManager
    {
        return MTL\Auth\AuthManager::instance();
    }
}

if (!function_exists('user')) {
    function user(): ?MTL\Models\User
    {
        return MTL\Auth\AuthManager::instance()->user();
    }
}

if (!function_exists('can')) {
    /**
     * Permission check for the current user, e.g. can('media.delete').
     */
    function can(string $permission, mixed $subject = null): bool
    {
        return MTL\Auth\AuthManager::instance()->can($permission, $subject);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(MTL\Core\Csrf::token()) . '">';
    }
}

if (!function_exists('method_field')) {
    /**
     * HTML forms only speak GET and POST; this smuggles the real verb through
     * so the router can dispatch PUT/PATCH/DELETE routes.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('icon')) {
    /**
     * Inline SVG icon. Returns already-escaped markup, so it is echoed
     * directly rather than passed through e().
     *
     * @param array<string,string> $attributes
     */
    function icon(string $name, int $size = 20, array $attributes = []): string
    {
        return MTL\Support\Icons::svg($name, $size, $attributes);
    }
}

if (!function_exists('nonce')) {
    /** The per-request CSP nonce for the one inline bootstrap script. */
    function nonce(): string
    {
        return MTL\Http\Middleware\SecurityHeadersMiddleware::nonce();
    }
}

if (!function_exists('setting')) {
    /** A site setting managed from the admin environment. */
    function setting(string $key, mixed $default = null): mixed
    {
        return MTL\Services\SettingsService::get($key, $default);
    }
}

if (!function_exists('route')) {
    /**
     * URL for a named route, e.g. route('trips.show', ['trip' => $slug]).
     *
     * @param array<string,scalar> $params
     */
    function route(string $name, array $params = []): string
    {
        return MTL\Core\Router::instance()->urlFor($name, $params);
    }
}

if (!function_exists('errors')) {
    /**
     * Validation messages for a field from the last failed submission.
     *
     * @return list<string>
     */
    function errors(string $field): array
    {
        $all = MTL\Core\Session::flashed('_errors', []);

        return is_array($all) && isset($all[$field]) && is_array($all[$field])
            ? array_values(array_map('strval', $all[$field]))
            : [];
    }
}

if (!function_exists('has_errors')) {
    function has_errors(?string $field = null): bool
    {
        $all = MTL\Core\Session::flashed('_errors', []);

        if (!is_array($all)) {
            return false;
        }

        return $field === null ? $all !== [] : !empty($all[$field]);
    }
}

if (!function_exists('old')) {
    /**
     * Value a form field held before a failed validation round-trip.
     */
    function old(string $key, mixed $default = ''): mixed
    {
        return MTL\Core\Session::flashed('_old.' . $key, $default);
    }
}

if (!function_exists('__')) {
    /**
     * Translates a key using the active locale, interpolating :placeholders.
     *
     * @param array<string,scalar> $replace
     */
    function __(string $key, array $replace = []): string
    {
        return MTL\Core\Translator::get($key, $replace);
    }
}

if (!function_exists('view')) {
    /**
     * @param array<string,mixed> $data
     */
    function view(string $template, array $data = []): MTL\Core\Response
    {
        return MTL\Core\Response::html(MTL\Core\View::render($template, $data));
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to, int $status = 302): MTL\Core\Response
    {
        return MTL\Core\Response::redirect($to, $status);
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): MTL\Core\Response
    {
        return MTL\Core\Response::json($data, $status);
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $value, int $maxLength = 96): string
    {
        return MTL\Support\Str::slug($value, $maxLength);
    }
}

if (!function_exists('logger')) {
    function logger(): MTL\Core\Logger
    {
        return MTL\Core\Logger::instance();
    }
}
