<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * An error that maps directly onto an HTTP status code.
 *
 * Throwing this from anywhere in a controller produces the right status and a
 * message safe to show a visitor; every other exception becomes a generic 500.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'You do not have access to this page.'): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = 'Please sign in to continue.'): self
    {
        return new self(401, $message);
    }

    public static function tooManyRequests(string $message, int $retryAfter): self
    {
        return new self(429, $message, ['Retry-After' => (string) $retryAfter]);
    }
}
