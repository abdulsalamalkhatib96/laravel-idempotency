<?php

namespace Abdulsalam\LaravelIdempotency\Data;

use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use DateTimeImmutable;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $id,
        public string $identityHash,
        public string $keyHash,
        public string $scopeHash,
        public string $fingerprint,
        public string $fingerprintVersion,
        public IdempotencyStatus $status,
        public string $profile,
        public ?string $ownerToken,
        public int $attempt,
        public int $replayCount,
        public string $requestMethod,
        public string $routeSignature,
        public ?StoredResponse $response,
        public ?string $errorCode,
        public array $metadata,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $leaseExpiresAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function leaseExpired(DateTimeImmutable $now): bool
    {
        return $this->leaseExpiresAt !== null && $this->leaseExpiresAt <= $now;
    }
}
