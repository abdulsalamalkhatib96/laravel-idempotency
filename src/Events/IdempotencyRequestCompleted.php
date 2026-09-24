<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;

final readonly class IdempotencyRequestCompleted
{
    public function __construct(public IdempotencyContext $context, public int $status) {}
}
