<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use DateTimeImmutable;

interface IdempotencyStore
{
    public function find(string $identityHash): ?IdempotencyRecord;

    public function createProcessing(IdempotencyRecord $record): bool;

    public function reclaim(
        string $identityHash,
        string $expectedStatus,
        string $ownerToken,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $expiresAt,
    ): bool;

    public function complete(
        string $identityHash,
        string $ownerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool;

    public function recoverCompleted(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool;

    public function markIndeterminate(
        string $identityHash,
        ?string $ownerToken,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool;

    public function markFailedSafe(
        string $identityHash,
        ?string $ownerToken,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool;

    public function recoverFailedSafe(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        string $reason,
        DateTimeImmutable $at,
        DateTimeImmutable $expiresAt,
    ): bool;

    public function incrementReplay(string $identityHash, DateTimeImmutable $at): void;

    public function mergeMetadata(string $identityHash, string $ownerToken, array $metadata, DateTimeImmutable $at): bool;

    public function forget(string $identityHash): bool;

    public function pruneExpired(DateTimeImmutable $now, int $limit): int;

    /** @return list<IdempotencyRecord> */
    public function recoveryCandidates(DateTimeImmutable $now, int $limit, ?string $profile = null): array;
}
