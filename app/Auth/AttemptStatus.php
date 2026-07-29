<?php

declare(strict_types=1);

namespace MTL\Auth;

defined('MTL_APP') || exit;

/** The outcome of a sign-in attempt. */
enum AttemptStatus: string
{
    case Success = 'success';

    /** Credentials were correct; a TOTP code is still needed. */
    case TwoFactorRequired = 'two_factor_required';

    case InvalidCredentials = 'invalid_credentials';

    /** Too many failed attempts for this account. */
    case Locked = 'locked';

    /** Too many attempts from this address, regardless of account. */
    case Throttled = 'throttled';

    /** The account exists but has been disabled by an administrator. */
    case Disabled = 'disabled';

    /** The account was invited but has never set a password. */
    case NotActivated = 'not_activated';
}
