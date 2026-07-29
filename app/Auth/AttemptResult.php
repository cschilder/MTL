<?php

declare(strict_types=1);

namespace MTL\Auth;

use MTL\Models\User;

defined('MTL_APP') || exit;

/**
 * Carries the outcome of AuthManager::attempt() together with whatever the
 * caller needs to act on it.
 */
final class AttemptResult
{
    private function __construct(
        public readonly AttemptStatus $status,
        public readonly ?User $user = null,
        public readonly int $retryAfter = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(AttemptStatus::Success, $user);
    }

    public static function twoFactorRequired(User $user): self
    {
        return new self(AttemptStatus::TwoFactorRequired, $user);
    }

    public static function invalid(): self
    {
        return new self(AttemptStatus::InvalidCredentials);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(AttemptStatus::Locked, null, $retryAfter);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(AttemptStatus::Throttled, null, $retryAfter);
    }

    public static function disabled(): self
    {
        return new self(AttemptStatus::Disabled);
    }

    public static function notActivated(User $user): self
    {
        return new self(AttemptStatus::NotActivated, $user);
    }

    public function succeeded(): bool
    {
        return $this->status === AttemptStatus::Success;
    }

    /**
     * A message safe to show the visitor.
     *
     * "Invalid credentials" deliberately does not distinguish an unknown
     * address from a wrong password, so the form cannot be used to find out
     * which addresses have accounts.
     */
    public function message(): string
    {
        return match ($this->status) {
            AttemptStatus::Success            => __('auth.welcome_back'),
            AttemptStatus::TwoFactorRequired  => __('auth.two_factor_prompt'),
            AttemptStatus::InvalidCredentials => __('auth.invalid_credentials'),
            AttemptStatus::Locked             => __('auth.locked', ['minutes' => (string) max(1, (int) ceil($this->retryAfter / 60))]),
            AttemptStatus::Throttled          => __('auth.throttled', ['minutes' => (string) max(1, (int) ceil($this->retryAfter / 60))]),
            AttemptStatus::Disabled           => __('auth.disabled'),
            AttemptStatus::NotActivated       => __('auth.not_activated'),
        };
    }
}
