<?php

namespace Abdulsalam\LaravelIdempotency\Scope;

use Abdulsalam\LaravelIdempotency\Contracts\TenantResolver;
use Illuminate\Http\Request;

final class HeaderTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        $header = config('idempotency.tenant.header');

        if (! is_string($header) || $header === '') {
            return null;
        }

        $value = $request->headers->get($header);

        return $value === null ? null : (string) $value;
    }
}
