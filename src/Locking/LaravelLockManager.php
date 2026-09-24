<?php

namespace Abdulsalam\LaravelIdempotency\Locking;

use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyLockException;
use Closure;
use Illuminate\Cache\CacheManager;
use Throwable;

final class LaravelLockManager implements LockManager
{
    public function __construct(private readonly CacheManager $cache) {}

    public function synchronized(
        string $store,
        string $key,
        int $seconds,
        int $waitSeconds,
        Closure $callback,
    ): mixed {
        try {
            $lock = $this->cache->store($store)->lock($key, $seconds);
        } catch (Throwable $e) {
            throw new IdempotencyLockException('Unable to create the idempotency lock.', previous: $e);
        }

        $deadline = microtime(true) + max(0, $waitSeconds);
        $acquired = false;

        do {
            try {
                $acquired = (bool) $lock->get();
            } catch (Throwable $e) {
                throw new IdempotencyLockException('Unable to acquire the idempotency lock.', previous: $e);
            }

            if ($acquired || $waitSeconds <= 0 || microtime(true) >= $deadline) {
                break;
            }

            usleep(50_000);
        } while (true);

        if (! $acquired) {
            throw new IdempotencyLockException(
                $waitSeconds > 0
                    ? 'Timed out acquiring the idempotency lock.'
                    : 'Unable to acquire the idempotency lock immediately.',
            );
        }

        try {
            $result = $callback();
        } catch (Throwable $callbackException) {
            try {
                $lock->release();
            } catch (Throwable) {
            }

            throw $callbackException;
        }

        try {
            $lock->release();
        } catch (Throwable $e) {
            throw new IdempotencyLockException('Idempotency operation succeeded but the claim lock could not be released.', previous: $e);
        }

        return $result;
    }
}
