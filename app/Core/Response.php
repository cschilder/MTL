<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * An HTTP response, built up by a controller and sent once by Application.
 *
 * Keeping send() in one place means headers are never emitted halfway through
 * a controller, which is what makes redirect-after-flash reliable.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $cookies = [];

    /** @var (callable():void)|null streaming body, used for media delivery */
    private $streamer = null;

    public function __construct(
        private string $body = '',
        private int $status = 200,
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return (new self($html, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $text, int $status = 200): self
    {
        return (new self($text, $status))->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        if (!Config::isProduction()) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (new self((string) json_encode($data, $flags), $status))
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function xml(string $xml, int $status = 200): self
    {
        return (new self($xml, $status))->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        // Only relative targets and URLs on our own host are allowed, so a
        // ?next= parameter can never bounce a visitor to another site.
        $safe = self::sanitiseRedirect($to);

        return (new self('', $status))->header('Location', $safe);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * Streams a body instead of buffering it, so a 400 MB video does not have
     * to fit in memory_limit.
     */
    public static function stream(callable $streamer, int $status = 200): self
    {
        $response = new self('', $status);
        $response->streamer = $streamer;

        return $response;
    }

    private static function sanitiseRedirect(string $to): string
    {
        if ($to === '') {
            return path('/');
        }

        // Protocol-relative ("//evil.com") and absolute URLs on a foreign host
        // both get replaced by the home page.
        if (str_starts_with($to, '//')) {
            return path('/');
        }

        if (preg_match('#^https?://#i', $to) === 1) {
            $host = parse_url($to, PHP_URL_HOST);
            $ownHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);

            return ($host !== null && $host === $ownHost) ? $to : path('/');
        }

        if (!str_starts_with($to, '/')) {
            return path($to);
        }

        return $to;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [$name, $value, $options];

        return $this;
    }

    public function status(?int $status = null): int|self
    {
        if ($status === null) {
            return $this->status;
        }

        $this->status = $status;

        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            foreach ($this->cookies as [$name, $value, $options]) {
                setcookie($name, $value, $options + [
                    'path'     => Request::basePath() . '/',
                    'secure'   => (bool) Config::get('session.secure', true),
                    'httponly' => true,
                    'samesite' => (string) Config::get('session.samesite', 'Lax'),
                ]);
            }
        }

        if ($this->streamer !== null) {
            ($this->streamer)();

            return;
        }

        // 204 and 304 must not carry a body; some proxies choke if they do.
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
