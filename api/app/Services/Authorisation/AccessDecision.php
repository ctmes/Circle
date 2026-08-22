<?php

namespace App\Services\Authorisation;

/**
 * The result of a policy evaluation. Denials carry a machine-readable reason so
 * every access.denied audit event says *why*, not just that it happened.
 */
final class AccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $message,
    ) {}

    public static function allow(string $reason = 'permitted'): self
    {
        return new self(true, $reason, 'Permitted.');
    }

    public static function deny(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }

    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason, 'message' => $this->message];
    }
}
