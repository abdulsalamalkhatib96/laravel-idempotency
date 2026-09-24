<?php

namespace Abdulsalam\LaravelIdempotency\Recovery;

use Symfony\Component\HttpFoundation\Response;

final readonly class RecoveryResult
{
    private function __construct(
        public RecoveryOutcome $outcome,
        public ?Response $response = null,
        public ?string $reason = null,
    ) {}

    public static function completed(Response $response): self
    {
        return new self(RecoveryOutcome::Completed, $response);
    }

    public static function safeToRetry(?string $reason = null): self
    {
        return new self(RecoveryOutcome::SafeToRetry, reason: $reason);
    }

    public static function indeterminate(?string $reason = null): self
    {
        return new self(RecoveryOutcome::Indeterminate, reason: $reason);
    }
}
