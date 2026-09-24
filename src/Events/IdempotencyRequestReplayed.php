<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;

final readonly class IdempotencyRequestReplayed
{
    public function __construct(public IdempotencyContext $context, public IdempotencyRecord $record) {}
}
