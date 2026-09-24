<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryResult;

interface RecoveryResolver
{
    public function recover(IdempotencyRecord $record): RecoveryResult;
}
