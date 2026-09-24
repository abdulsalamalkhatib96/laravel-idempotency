<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;

final readonly class IdempotencyRequestClaimed
{
    public function __construct(public IdempotencyContext $context, public IdempotencyRecord $record) {}
}
