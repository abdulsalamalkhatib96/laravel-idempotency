<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;

final readonly class IdempotencyBecameIndeterminate
{
    public function __construct(public IdempotencyContext $context, public string $reason) {}
}
