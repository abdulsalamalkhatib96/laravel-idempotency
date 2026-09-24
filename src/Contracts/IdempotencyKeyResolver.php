<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Illuminate\Http\Request;

interface IdempotencyKeyResolver
{
    public function resolve(Request $request): ?string;
}
