<?php

namespace Abdulsalam\LaravelIdempotency\Recovery;

enum RecoveryOutcome: string
{
    case Completed = 'completed';
    case SafeToRetry = 'safe_to_retry';
    case Indeterminate = 'indeterminate';
}
