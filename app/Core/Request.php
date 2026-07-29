<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * The current HTTP request.
 *
 * Constructed once by Application and passed down to controllers; the static
 * helpers exist because basePath() is needed by url()/asset() before any
 * instance is available.
 */
final class Request
{
    private static ?string $basePath = null;
    private static ?self $current = null;

    /** @var array<string,string> */
    private array $routeParams = [];

    /** @var array<string,mixed>|null decoded JSON body, resolved lazily */
    private ?array $jsonBody = null;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string,mixed> */
        public readonly array $query,
        /** @var array<string,mixed> */
        public readonly array $body,
        /** @var array<string,mixed> */
        public readonly array $files,
        /** @var array<string,string> */
        public readonly array $headers,
        /** @var array<string,string> */
        public readonly array $cookies,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Browsers cannot send PUT/PATCH/DELETE from a form, so the router
        // honours a _method field on POST requests. Only POST may be
        // overridden — allowing it on GET would turn a link into a delete.
        if ($method === 'POST') {
            $override = strtoupper((string) ($_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return self::$current = new self(
            method:  $method,
            path:    self::resolvePath(),
            query:   $_GET,
            body:    $_POST,
            files:   $_FILES,
            headers: self::resolveHeaders(),
            cookies: array_map('strval', $_COOKIE),
        );
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Directory the application is installed in, relative to the domain root.
     * Empty string when it lives at the root, which is the case on
     * https://mtl.r010.space.
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return self::$basePath = ($dir === '' || $dir === '.') ? '' : $dir;
    }

    /** Overrides the detected base path. Used by the test runner. */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    private static function resolvePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Without mod_rewrite the request arrives as index.php?/some/path.
        if (($_SERVER['QUERY_STRING'] ?? '') !== '' && str_starts_with((string) $_SERVER['QUERY_STRING'], '/')) {
            $uri = (string) $_SERVER['QUERY_STRING'];
        }

        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim(rawurldecode($path), '/');

        // Collapse any traversal a client tries to smuggle in before the
        // router or a controller sees it.
        return preg_replace('#/+#', '/', str_replace(['../', './'], '', $path)) ?? '/';
    }

    /** @return array<string,string> */
    private static function resolveHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        // Strato sits behind a load balancer, so the socket address is the
        // proxy. Take the left-most entry of X-Forwarded-For when present and
        // fall back to REMOTE_ADDR.
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== null && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? (string) $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', '') ?? '', 0, 512);
    }

    public function isSecure(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || $this->header('x-forwarded-proto') === 'https'
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    /** True for fetch()/XMLHttpRequest calls, which get JSON instead of HTML. */
    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '') ?? '';

        return $this->header('x-requested-with') === 'XMLHttpRequest'
            || str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', '') ?? '', 'application/json');
    }

    /**
     * A request value, looked up in the route parameters, then the body, then
     * the query string, then a JSON body.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $this->json()[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->input($key, null);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<int,string> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        if (!is_array($value)) {
            return $value === null || $value === '' ? [] : [(string) $value];
        }

        return array_values(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        if (!$this->isJson()) {
            return $this->jsonBody = [];
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $this->jsonBody = [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonBody = [];
        }

        return $this->jsonBody = is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> everything the client sent, merged */
    public function all(): array
    {
        return array_merge($this->query, $this->json(), $this->body, $this->routeParams);
    }

    /**
     * @param array<string,string> $params
     */
    public function withRouteParams(array $params): self
    {
        $this->routeParams = $params;

        return $this;
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Uploaded file metadata for a field, normalised to a single-file shape.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || !isset($file['tmp_name'])) {
            return null;
        }

        // A multi-file field arrives as parallel arrays; take the first entry.
        if (is_array($file['tmp_name'])) {
            if (($file['tmp_name'][0] ?? '') === '') {
                return null;
            }

            return [
                'name'     => (string) ($file['name'][0] ?? ''),
                'type'     => (string) ($file['type'][0] ?? ''),
                'tmp_name' => (string) $file['tmp_name'][0],
                'error'    => (int) ($file['error'][0] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int) ($file['size'][0] ?? 0),
            ];
        }

        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return [
            'name'     => (string) $file['name'],
            'type'     => (string) $file['type'],
            'tmp_name' => (string) $file['tmp_name'],
            'error'    => (int) $file['error'],
            'size'     => (int) $file['size'],
        ];
    }

    /**
     * The full URL of this request, used for "return to where you were"
     * redirects after login.
     */
    public function fullUrl(): string
    {
        $query = $this->query;
        unset($query['_token']);

        return path($this->path, $query);
    }
}
