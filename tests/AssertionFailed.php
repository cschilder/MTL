<?php

declare(strict_types=1);

namespace MTL\Tests;

defined('MTL_APP') || exit;

/**
 * Thrown by a failed assertion so the runner can stop the current test method
 * without aborting the whole suite.
 */
final class AssertionFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}
