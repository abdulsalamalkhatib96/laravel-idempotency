<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Illuminate\Http\Request;

interface ScopeResolver
{
    public function resolve(Request $request, ?string $principal, ?string $tenant): string;

    public function operation(Request $request): string;
}
