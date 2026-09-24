<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyOwnershipLostException;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use JsonException;
use LogicException;

final class IdempotencyManager
{
    public function __construct(
        private readonly Container $container,
        private readonly OperationRunner $operations,
        private readonly StoreManager $stores,
        private readonly LockManager $locks,
    ) {}

    public function current(): ?IdempotencyContext
    {
        return $this->holder()->get();
    }

    public function isReplay(): bool
    {
        return $this->holder()->isReplay();
    }

    public function run(
        string $scope,
        string $key,
        mixed $payload,
        callable $callback,
        ?string $profile = null,
    ): mixed {
        return $this->operations->run($scope, $key, $payload, $callback, $profile);
    }

    /**
     * Persist application-defined reconciliation metadata while the current execution owns the record.
     * Keep this data minimal and do not store secrets or unnecessary PII.
     *
     * @throws JsonException
     */
    public function remember(array $metadata): void
    {
        // Validate JSON serializability before touching storage.
        json_encode($metadata, JSON_THROW_ON_ERROR);

        $holder = $this->holder();
        $context = $holder->get();
        $owner = $holder->ownerToken();

        if ($context === null || $holder->isReplay() || $owner === null) {
            throw new LogicException('Idempotency metadata can only be written by the active execution owner.');
        }

        $saved = $this->locks->synchronized(
            $context->profile->lockStore,
            'idempotency:claim:'.$context->identityHash,
            $context->profile->lockSeconds,
            $context->profile->lockWaitSeconds,
            fn (): bool => $this->stores
                ->store($context->profile->store)
                ->mergeMetadata($context->identityHash, $owner, $metadata, new DateTimeImmutable()),
        );

        if (! $saved) {
            throw new IdempotencyOwnershipLostException('Unable to persist idempotency metadata because execution ownership was lost.');
        }
    }

    public function reference(string $reference): void
    {
        $this->remember(['reference' => $reference]);
    }

    public function boundaryKey(string $boundary): string
    {
        $context = $this->current();
        if ($context === null) {
            throw new LogicException('No active idempotency context.');
        }

        return $this->boundaryKeyFromHash($context->keyHash, $boundary);
    }

    public function boundaryKeyForRecord(IdempotencyRecord $record, string $boundary): string
    {
        return $this->boundaryKeyFromHash($record->keyHash, $boundary);
    }

    private function boundaryKeyFromHash(string $keyHash, string $boundary): string
    {
        $secret = (string) config('idempotency.secret');
        if ($secret === '') {
            throw new LogicException('Idempotency secret is not configured.');
        }

        return hash_hmac('sha256', $boundary.'|'.$keyHash, $secret);
    }

    private function holder(): CurrentIdempotencyContext
    {
        return $this->container->make(CurrentIdempotencyContext::class);
    }
}
