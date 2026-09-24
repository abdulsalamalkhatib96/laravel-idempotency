<?php

namespace Abdulsalam\LaravelIdempotency\Tests\Feature;

use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Exceptions\FingerprintConflictException;
use Abdulsalam\LaravelIdempotency\Tests\TestCase;

final class LockManagerTest extends TestCase
{
    public function test_callback_exceptions_are_not_reclassified_as_lock_failures(): void
    {
        $locks = $this->app->make(LockManager::class);

        $this->expectException(FingerprintConflictException::class);

        $locks->synchronized('array', 'idempotency:test:callback-exception', 5, 0, function (): never {
            throw new FingerprintConflictException('fingerprint mismatch');
        });
    }

    public function test_false_callback_result_is_returned_normally(): void
    {
        $locks = $this->app->make(LockManager::class);

        self::assertFalse(
            $locks->synchronized('array', 'idempotency:test:false-result', 5, 0, fn (): bool => false),
        );
    }
}
