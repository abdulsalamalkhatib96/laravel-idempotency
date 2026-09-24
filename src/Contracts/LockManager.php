<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Closure;

interface LockManager
{
    public function synchronized(
        string $store,
        string $key,
        int $seconds,
        int $waitSeconds,
        Closure $callback,
    ): mixed;
}
