<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Illuminate\Http\Request;

interface PrincipalResolver
{
    public function resolve(Request $request): ?string;
}
