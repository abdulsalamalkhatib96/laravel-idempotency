<?php

namespace Abdulsalam\LaravelIdempotency\Keys;

use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyKeyException;

final class KeyValidator
{
    public function validate(string $key): void
    {
        $max = (int) config('idempotency.key_max_length', 255);

        if ($key === '') {
            throw new InvalidIdempotencyKeyException('Idempotency key cannot be empty.');
        }

        if (strlen($key) > $max) {
            throw new InvalidIdempotencyKeyException("Idempotency key exceeds {$max} bytes.");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidIdempotencyKeyException('Idempotency key contains control characters.');
        }
    }
}
