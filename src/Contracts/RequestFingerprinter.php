<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Illuminate\Http\Request;

interface RequestFingerprinter
{
    public function fingerprint(Request $request, string $operation): string;
}
