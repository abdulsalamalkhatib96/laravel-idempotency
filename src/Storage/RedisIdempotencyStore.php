<?php

namespace Abdulsalam\LaravelIdempotency\Storage;

use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyStore;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\Support\RecordSerializer;
use DateTimeImmutable;
use Illuminate\Cache\CacheManager;

final class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly RecordSerializer $serializer,
    ) {}

    public function find(string $identityHash): ?IdempotencyRecord
    {
        $payload = $this->repository()->get($this->key($identityHash));
        if (! is_array($payload)) {
            return null;
        }
        return $this->serializer->fromArray($payload);
    }

    public function createProcessing(IdempotencyRecord $record): bool
    {
        $key = $this->key($record->identityHash);
        $repository = $this->repository();

        if ($repository->has($key)) {
            return false;
        }

        return $repository->forever($key, $this->serializer->toArray($record));
    }

    public function reclaim(
        string $identityHash,
        string $expectedStatus,
        string $ownerToken,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        $record = $this->find($identityHash);
        if ($record === null || $record->status->value !== $expectedStatus) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['status'] = IdempotencyStatus::Processing->value;
        $data['owner_token'] = $ownerToken;
        $data['attempt'] = $record->attempt + 1;
        $data['error_code'] = null;
        $data['started_at'] = $startedAt->format(DATE_ATOM);
        $data['lease_expires_at'] = $leaseExpiresAt->format(DATE_ATOM);
        $data['completed_at'] = null;
        $data['expires_at'] = $expiresAt->format(DATE_ATOM);

        return $this->repository()->forever($this->key($identityHash), $data);
    }

    public function complete(
        string $identityHash,
        string $ownerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        $record = $this->find($identityHash);
        if ($record === null || $record->status !== IdempotencyStatus::Processing || $record->ownerToken !== $ownerToken) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['status'] = IdempotencyStatus::Completed->value;
        $data['owner_token'] = null;
        $data['response_status'] = $response->status;
        $data['response_headers'] = $response->headers;
        $data['response_body'] = $response->body;
        $data['response_encoding'] = $response->encoding;
        $data['response_checksum'] = $response->checksum;
        $data['response_size'] = $response->size;
        $data['completed_at'] = $completedAt->format(DATE_ATOM);
        $data['lease_expires_at'] = null;
        $data['expires_at'] = $expiresAt->format(DATE_ATOM);

        return $this->repository()->put($this->key($identityHash), $data, $this->ttl($expiresAt));
    }

    public function recoverCompleted(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        $record = $this->find($identityHash);
        if ($record === null || $record->status->value !== $expectedStatus || $record->ownerToken !== $expectedOwnerToken) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['status'] = IdempotencyStatus::Completed->value;
        $data['owner_token'] = null;
        $data['response_status'] = $response->status;
        $data['response_headers'] = $response->headers;
        $data['response_body'] = $response->body;
        $data['response_encoding'] = $response->encoding;
        $data['response_checksum'] = $response->checksum;
        $data['response_size'] = $response->size;
        $data['error_code'] = null;
        $data['completed_at'] = $completedAt->format(DATE_ATOM);
        $data['lease_expires_at'] = null;
        $data['expires_at'] = $expiresAt->format(DATE_ATOM);

        return $this->repository()->put($this->key($identityHash), $data, $this->ttl($expiresAt));
    }

    public function markIndeterminate(string $identityHash, ?string $ownerToken, string $errorCode, DateTimeImmutable $at): bool
    {
        return $this->transition($identityHash, $ownerToken, IdempotencyStatus::Indeterminate, $errorCode, $at);
    }

    public function markFailedSafe(string $identityHash, ?string $ownerToken, string $errorCode, DateTimeImmutable $at): bool
    {
        return $this->transition($identityHash, $ownerToken, IdempotencyStatus::FailedSafe, $errorCode, $at);
    }

    public function recoverFailedSafe(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        string $reason,
        DateTimeImmutable $at,
        DateTimeImmutable $expiresAt,
    ): bool {
        $record = $this->find($identityHash);
        if ($record === null || $record->status->value !== $expectedStatus || $record->ownerToken !== $expectedOwnerToken) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['status'] = IdempotencyStatus::FailedSafe->value;
        $data['owner_token'] = null;
        $data['error_code'] = $reason;
        $data['lease_expires_at'] = null;
        $data['last_seen_at'] = $at->format(DATE_ATOM);
        $data['expires_at'] = $expiresAt->format(DATE_ATOM);

        return $this->repository()->put($this->key($identityHash), $data, $this->ttl($expiresAt));
    }

    public function incrementReplay(string $identityHash, DateTimeImmutable $at): void
    {
        $record = $this->find($identityHash);
        if ($record === null) {
            return;
        }

        $data = $this->serializer->toArray($record);
        $data['replay_count'] = $record->replayCount + 1;
        $data['last_seen_at'] = $at->format(DATE_ATOM);
        $this->repository()->put($this->key($identityHash), $data, $this->ttl($record->expiresAt));
    }

    public function mergeMetadata(string $identityHash, string $ownerToken, array $metadata, DateTimeImmutable $at): bool
    {
        $record = $this->find($identityHash);
        if ($record === null || $record->status !== IdempotencyStatus::Processing || $record->ownerToken !== $ownerToken) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['metadata'] = array_replace_recursive($record->metadata, $metadata);
        $data['last_seen_at'] = $at->format(DATE_ATOM);

        return $this->repository()->forever($this->key($identityHash), $data);
    }

    public function forget(string $identityHash): bool
    {
        return $this->repository()->forget($this->key($identityHash));
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit): int
    {
        return 0;
    }

    public function recoveryCandidates(DateTimeImmutable $now, int $limit, ?string $profile = null): array
    {
        return [];
    }

    private function transition(
        string $identityHash,
        ?string $ownerToken,
        IdempotencyStatus $status,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool {
        $record = $this->find($identityHash);
        if ($record === null || $record->status !== IdempotencyStatus::Processing) {
            return false;
        }

        if ($ownerToken !== null && $record->ownerToken !== $ownerToken) {
            return false;
        }

        $data = $this->serializer->toArray($record);
        $data['status'] = $status->value;
        $data['owner_token'] = null;
        $data['error_code'] = $errorCode;
        $data['lease_expires_at'] = null;
        $data['last_seen_at'] = $at->format(DATE_ATOM);

        if ($status === IdempotencyStatus::Indeterminate) {
            return $this->repository()->forever($this->key($identityHash), $data);
        }

        return $this->repository()->put($this->key($identityHash), $data, $this->ttl($record->expiresAt));
    }

    private function repository()
    {
        return $this->cache->store((string) config('idempotency.redis.cache_store', 'redis'));
    }

    private function key(string $identityHash): string
    {
        return (string) config('idempotency.redis.prefix', 'idempotency:v1:').'record:'.$identityHash;
    }

    private function ttl(DateTimeImmutable $expiresAt): int
    {
        return max(1, $expiresAt->getTimestamp() - time());
    }
}
