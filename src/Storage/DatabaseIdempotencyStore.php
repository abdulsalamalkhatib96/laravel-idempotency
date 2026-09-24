<?php

namespace Abdulsalam\LaravelIdempotency\Storage;

use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyStore;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyStorageException;
use Abdulsalam\LaravelIdempotency\Support\RecordSerializer;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Throwable;

final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecordSerializer $serializer,
    ) {}

    public function find(string $identityHash): ?IdempotencyRecord
    {
        $row = $this->query()->where('identity_hash', $identityHash)->first();
        return $row === null ? null : $this->serializer->fromArray((array) $row);
    }

    public function createProcessing(IdempotencyRecord $record): bool
    {
        $data = $this->serializer->toArray($record);
        $data['response_headers'] = null;
        $data['metadata'] = json_encode($record->metadata, JSON_THROW_ON_ERROR);
        $data['started_at'] = $this->dbDate($record->startedAt);
        $data['lease_expires_at'] = $this->dbDate($record->leaseExpiresAt);
        $data['completed_at'] = null;
        $data['last_seen_at'] = $this->dbDate($record->lastSeenAt);
        $data['expires_at'] = $this->dbDate($record->expiresAt);
        $data['created_at'] = $this->dbDate(new DateTimeImmutable());
        $data['updated_at'] = $data['created_at'];

        try {
            return $this->query()->insert($data);
        } catch (QueryException $e) {
            if ($this->find($record->identityHash) !== null) {
                return false;
            }
            throw new IdempotencyStorageException('Unable to create idempotency record.', previous: $e);
        } catch (Throwable $e) {
            throw new IdempotencyStorageException('Unable to create idempotency record.', previous: $e);
        }
    }

    public function reclaim(
        string $identityHash,
        string $expectedStatus,
        string $ownerToken,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        return $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', $expectedStatus)
            ->update([
                'status' => IdempotencyStatus::Processing->value,
                'owner_token' => $ownerToken,
                'attempt' => $this->db->connection(config('idempotency.database.connection'))->raw('attempt + 1'),
                'error_code' => null,
                'started_at' => $this->dbDate($startedAt),
                'lease_expires_at' => $this->dbDate($leaseExpiresAt),
                'completed_at' => null,
                'expires_at' => $this->dbDate($expiresAt),
                'updated_at' => $this->dbDate(new DateTimeImmutable()),
            ]) === 1;
    }

    public function complete(
        string $identityHash,
        string $ownerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        return $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', IdempotencyStatus::Processing->value)
            ->where('owner_token', $ownerToken)
            ->update([
                'status' => IdempotencyStatus::Completed->value,
                'owner_token' => null,
                'response_status' => $response->status,
                'response_headers' => json_encode($response->headers, JSON_THROW_ON_ERROR),
                'response_body' => $response->body,
                'response_encoding' => $response->encoding,
                'response_checksum' => $response->checksum,
                'response_size' => $response->size,
                'completed_at' => $this->dbDate($completedAt),
                'lease_expires_at' => null,
                'expires_at' => $this->dbDate($expiresAt),
                'updated_at' => $this->dbDate(new DateTimeImmutable()),
            ]) === 1;
    }

    public function recoverCompleted(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        StoredResponse $response,
        DateTimeImmutable $completedAt,
        DateTimeImmutable $expiresAt,
    ): bool {
        return $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', $expectedStatus)
            ->when(
                $expectedOwnerToken === null,
                fn ($query) => $query->whereNull('owner_token'),
                fn ($query) => $query->where('owner_token', $expectedOwnerToken),
            )
            ->update([
                'status' => IdempotencyStatus::Completed->value,
                'owner_token' => null,
                'response_status' => $response->status,
                'response_headers' => json_encode($response->headers, JSON_THROW_ON_ERROR),
                'response_body' => $response->body,
                'response_encoding' => $response->encoding,
                'response_checksum' => $response->checksum,
                'response_size' => $response->size,
                'error_code' => null,
                'completed_at' => $this->dbDate($completedAt),
                'lease_expires_at' => null,
                'expires_at' => $this->dbDate($expiresAt),
                'updated_at' => $this->dbDate(new DateTimeImmutable()),
            ]) === 1;
    }

    public function markIndeterminate(
        string $identityHash,
        ?string $ownerToken,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool {
        return $this->transitionFailure($identityHash, $ownerToken, IdempotencyStatus::Indeterminate, $errorCode, $at);
    }

    public function markFailedSafe(
        string $identityHash,
        ?string $ownerToken,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool {
        return $this->transitionFailure($identityHash, $ownerToken, IdempotencyStatus::FailedSafe, $errorCode, $at);
    }

    public function recoverFailedSafe(
        string $identityHash,
        string $expectedStatus,
        ?string $expectedOwnerToken,
        string $reason,
        DateTimeImmutable $at,
        DateTimeImmutable $expiresAt,
    ): bool {
        return $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', $expectedStatus)
            ->when(
                $expectedOwnerToken === null,
                fn ($query) => $query->whereNull('owner_token'),
                fn ($query) => $query->where('owner_token', $expectedOwnerToken),
            )
            ->update([
                'status' => IdempotencyStatus::FailedSafe->value,
                'owner_token' => null,
                'error_code' => $reason,
                'lease_expires_at' => null,
                'last_seen_at' => $this->dbDate($at),
                'expires_at' => $this->dbDate($expiresAt),
                'updated_at' => $this->dbDate($at),
            ]) === 1;
    }

    public function incrementReplay(string $identityHash, DateTimeImmutable $at): void
    {
        $this->query()->where('identity_hash', $identityHash)->update([
            'replay_count' => $this->db->connection(config('idempotency.database.connection'))->raw('replay_count + 1'),
            'last_seen_at' => $this->dbDate($at),
            'updated_at' => $this->dbDate($at),
        ]);
    }

    public function mergeMetadata(string $identityHash, string $ownerToken, array $metadata, DateTimeImmutable $at): bool
    {
        $record = $this->find($identityHash);
        if ($record === null || $record->status !== IdempotencyStatus::Processing || $record->ownerToken !== $ownerToken) {
            return false;
        }

        $merged = array_replace_recursive($record->metadata, $metadata);

        return $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', IdempotencyStatus::Processing->value)
            ->where('owner_token', $ownerToken)
            ->update([
                'metadata' => json_encode($merged, JSON_THROW_ON_ERROR),
                'updated_at' => $this->dbDate($at),
            ]) === 1;
    }

    public function forget(string $identityHash): bool
    {
        return $this->query()->where('identity_hash', $identityHash)->delete() > 0;
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit): int
    {
        $ids = $this->query()
            ->where('expires_at', '<=', $this->dbDate($now))
            ->whereNotIn('status', [IdempotencyStatus::Processing->value, IdempotencyStatus::Indeterminate->value])
            ->limit($limit)
            ->pluck('identity_hash')
            ->all();

        return $ids === [] ? 0 : $this->query()->whereIn('identity_hash', $ids)->delete();
    }

    public function recoveryCandidates(DateTimeImmutable $now, int $limit, ?string $profile = null): array
    {
        $query = $this->query()
            ->where(function ($q) use ($now): void {
                $q->where('status', IdempotencyStatus::Indeterminate->value)
                    ->orWhere(function ($q) use ($now): void {
                        $q->where('status', IdempotencyStatus::Processing->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $this->dbDate($now));
                    });
            });

        if ($profile !== null) {
            $query->where('profile', $profile);
        }

        return $query
            ->orderBy('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): IdempotencyRecord => $this->serializer->fromArray((array) $row))
            ->all();
    }

    private function transitionFailure(
        string $identityHash,
        ?string $ownerToken,
        IdempotencyStatus $status,
        string $errorCode,
        DateTimeImmutable $at,
    ): bool {
        $query = $this->query()
            ->where('identity_hash', $identityHash)
            ->where('status', IdempotencyStatus::Processing->value);

        if ($ownerToken !== null) {
            $query->where('owner_token', $ownerToken);
        }

        return $query->update([
            'status' => $status->value,
            'owner_token' => null,
            'error_code' => $errorCode,
            'lease_expires_at' => null,
            'last_seen_at' => $this->dbDate($at),
            'updated_at' => $this->dbDate($at),
        ]) === 1;
    }

    private function query()
    {
        return $this->db
            ->connection(config('idempotency.database.connection'))
            ->table((string) config('idempotency.database.table', 'idempotency_records'));
    }

    private function dbDate(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }
}
