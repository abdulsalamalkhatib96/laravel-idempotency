<?php

namespace Abdulsalam\LaravelIdempotency\Data;

final readonly class StoredResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $encoding,
        public string $checksum,
        public int $size,
    ) {}
}
