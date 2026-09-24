<?php

namespace Abdulsalam\LaravelIdempotency\Support;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use DateTimeImmutable;

final class RecordSerializer
{
    public function toArray(IdempotencyRecord $record): array
    {
        return [
            'id' => $record->id,
            'identity_hash' => $record->identityHash,
            'key_hash' => $record->keyHash,
            'scope_hash' => $record->scopeHash,
            'fingerprint' => $record->fingerprint,
            'fingerprint_version' => $record->fingerprintVersion,
            'status' => $record->status->value,
            'profile' => $record->profile,
            'owner_token' => $record->ownerToken,
            'attempt' => $record->attempt,
            'replay_count' => $record->replayCount,
            'request_method' => $record->requestMethod,
            'route_signature' => $record->routeSignature,
            'response_status' => $record->response?->status,
            'response_headers' => $record->response?->headers,
            'response_body' => $record->response?->body,
            'response_encoding' => $record->response?->encoding,
            'response_checksum' => $record->response?->checksum,
            'response_size' => $record->response?->size,
            'error_code' => $record->errorCode,
            'metadata' => $record->metadata,
            'started_at' => $record->startedAt->format(DATE_ATOM),
            'lease_expires_at' => $record->leaseExpiresAt?->format(DATE_ATOM),
            'completed_at' => $record->completedAt?->format(DATE_ATOM),
            'last_seen_at' => $record->lastSeenAt?->format(DATE_ATOM),
            'expires_at' => $record->expiresAt->format(DATE_ATOM),
        ];
    }

    public function fromArray(array $row): IdempotencyRecord
    {
        $response = null;

        if (isset($row['response_status'], $row['response_encoding'], $row['response_checksum'])) {
            $response = new StoredResponse(
                status: (int) $row['response_status'],
                headers: $this->arrayValue($row['response_headers'] ?? []),
                body: (string) ($row['response_body'] ?? ''),
                encoding: (string) $row['response_encoding'],
                checksum: (string) $row['response_checksum'],
                size: (int) ($row['response_size'] ?? strlen((string) ($row['response_body'] ?? ''))),
            );
        }

        return new IdempotencyRecord(
            id: (string) $row['id'],
            identityHash: (string) $row['identity_hash'],
            keyHash: (string) $row['key_hash'],
            scopeHash: (string) $row['scope_hash'],
            fingerprint: (string) $row['fingerprint'],
            fingerprintVersion: (string) $row['fingerprint_version'],
            status: IdempotencyStatus::from((string) $row['status']),
            profile: (string) ($row['profile'] ?? 'default'),
            ownerToken: isset($row['owner_token']) ? (string) $row['owner_token'] : null,
            attempt: (int) ($row['attempt'] ?? 1),
            replayCount: (int) ($row['replay_count'] ?? 0),
            requestMethod: (string) $row['request_method'],
            routeSignature: (string) $row['route_signature'],
            response: $response,
            errorCode: isset($row['error_code']) ? (string) $row['error_code'] : null,
            metadata: $this->arrayValue($row['metadata'] ?? []),
            startedAt: new DateTimeImmutable((string) $row['started_at']),
            leaseExpiresAt: $this->date($row['lease_expires_at'] ?? null),
            completedAt: $this->date($row['completed_at'] ?? null),
            lastSeenAt: $this->date($row['last_seen_at'] ?? null),
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value);
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
