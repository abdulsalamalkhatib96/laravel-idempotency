<?php

namespace Abdulsalam\LaravelIdempotency\Events;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryOutcome;

final readonly class IdempotencyRecovered
{
    public function __construct(
        public IdempotencyRecord $record,
        public RecoveryOutcome $outcome,
    ) {}
}
