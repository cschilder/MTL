<?php

declare(strict_types=1);

namespace MTL\Core;

defined('MTL_APP') || exit;

/**
 * A fragment of SQL that the query builder inserts verbatim.
 *
 * Only ever construct this from a literal in application code — the contents
 * are not escaped.
 */
final class Expression implements \Stringable
{
    public function __construct(private readonly string $sql)
    {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
