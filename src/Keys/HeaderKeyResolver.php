<?php

namespace Abdulsalam\LaravelIdempotency\Keys;

use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyKeyResolver;
use Illuminate\Http\Request;

final class HeaderKeyResolver implements IdempotencyKeyResolver
{
    public function resolve(Request $request): ?string
    {
        $value = $request->headers->get((string) config('idempotency.header', 'Idempotency-Key'));

        if ($value === null) {
            return null;
        }

        return trim((string) $value, " \t\n\r\0\x0B\"");
    }
}
