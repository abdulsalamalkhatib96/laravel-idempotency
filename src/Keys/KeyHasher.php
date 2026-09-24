<?php

namespace Abdulsalam\LaravelIdempotency\Keys;

use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;

final class KeyHasher
{
    public function hash(string $value): string
    {
        $secret = (string) config('idempotency.secret');

        if ($secret === '') {
            throw new InvalidIdempotencyConfigurationException('IDEMPOTENCY_SECRET (or APP_KEY fallback) is required to hash idempotency keys.');
        }

        return hash_hmac('sha256', $value, $secret);
    }
}
