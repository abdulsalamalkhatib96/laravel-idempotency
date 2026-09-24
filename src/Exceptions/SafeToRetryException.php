<?php

namespace Abdulsalam\LaravelIdempotency\Exceptions;

use RuntimeException;
use Throwable;

final class SafeToRetryException extends RuntimeException
{
    public function __construct(string $message = 'The operation failed before side effects and may be retried.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
