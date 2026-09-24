<?php

namespace Abdulsalam\LaravelIdempotency\Enums;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case FailedSafe = 'failed_safe';
    case Indeterminate = 'indeterminate';
}
