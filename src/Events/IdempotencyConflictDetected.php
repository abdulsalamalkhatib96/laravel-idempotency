<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;

final readonly class IdempotencyConflictDetected
{
    public function __construct(public IdempotencyContext $context, public string $reason) {}
}
