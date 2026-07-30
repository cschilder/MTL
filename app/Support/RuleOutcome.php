<?php

declare(strict_types=1);

namespace MTL\Support;

defined('MTL_APP') || exit;

/**
 * What a single validation rule decided.
 *
 * A rule either rejects the value, accepts it as it stands, or replaces it with
 * a coerced one — "3" becomes 3, "on" becomes true. The first two need a signal
 * that cannot be mistaken for data, which is why they are enum cases rather than
 * `true` and `false`.
 *
 * Booleans are the reason. When `false` meant "rejected", the boolean rule could
 * not report a value of false: a checkbox submitted as "0" or "off" was read as a
 * failed rule, the field was dropped from the validated data without an error
 * being recorded, and the update then left the column at its old value. Turning
 * a setting off silently did nothing.
 */
enum RuleOutcome
{
    /** The value is acceptable exactly as it is. */
    case Passed;

    /** The value is not acceptable; an error has been recorded for the field. */
    case Failed;
}
