<?php

namespace Abdulsalam\LaravelIdempotency\Data;

final readonly class IdempotencyContext
{
    public function __construct(
        public string $key,
        public string $keyHash,
        public string $scope,
        public string $scopeHash,
        public string $identityHash,
        public string $fingerprint,
        public string $fingerprintVersion,
        public ?string $principal,
        public string $operation,
        public IdempotencyProfile $profile,
    ) {}
}
