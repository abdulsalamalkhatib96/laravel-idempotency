<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Illuminate\Http\Request;

interface TenantResolver
{
    public function resolve(Request $request): ?string;
}
