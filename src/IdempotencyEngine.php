<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Contracts\ResponseCodec;
use Abdulsalam\LaravelIdempotency\Data\ClaimDecision;
use Abdulsalam\LaravelIdempotency\Data\ClaimDecisionType;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyBecameIndeterminate;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyConflictDetected;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyRequestClaimed;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyRequestCompleted;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyRequestReplayed;
use Abdulsalam\LaravelIdempotency\Exceptions\ConcurrentIdempotencyRequestException;
use Abdulsalam\LaravelIdempotency\Exceptions\FingerprintConflictException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyIndeterminateException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyOwnershipLostException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyStorageException;
use Abdulsalam\LaravelIdempotency\Exceptions\ResponseNotReplayableException;
use Abdulsalam\LaravelIdempotency\Exceptions\SafeToRetryException;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IdempotencyEngine
{
    public function __construct(
        private readonly StoreManager $stores,
        private readonly LockManager $locks,
        private readonly ResponseCodec $codec,
        private readonly Dispatcher $events,
        private readonly Container $container,
    ) {}

    public function execute(IdempotencyContext $context, callable $next): Response
    {
        $store = $this->stores->store($context->profile->store);
        $decision = $this->claimWithPolicy($context);

        if ($decision->type === ClaimDecisionType::Replay) {
            if ($decision->record->response === null) {
                throw new IdempotencyStorageException('Completed idempotency record has no stored response.');
            }

            $store->incrementReplay($context->identityHash, new DateTimeImmutable());
            $current = $this->container->make(CurrentIdempotencyContext::class);
            $current->set($context, true);
            $this->events->dispatch(new IdempotencyRequestReplayed($context, $decision->record));

            try {
                return $this->codec->decode($decision->record->response, $context->profile);
            } finally {
                $current->clear();
            }
        }

        $owner = $decision->ownerToken;
        if ($owner === null) {
            throw new IdempotencyStorageException('Execution claim is missing an owner token.');
        }

        $current = $this->container->make(CurrentIdempotencyContext::class);
        $current->set($context, false, $owner);

        try {
            $response = $next();

            if (! $response instanceof Response) {
                throw new IdempotencyStorageException('Idempotent HTTP operations must return a Symfony Response instance.');
            }

            try {
                $stored = $this->codec->encode($response, $context->profile);
            } catch (ResponseNotReplayableException $e) {
                $this->markIndeterminate($context, $owner, 'response_not_replayable');
                throw $e;
            }

            $completedAt = new DateTimeImmutable();
            $expiresAt = $completedAt->modify('+'.$context->profile->ttlSeconds.' seconds');

            $saved = $this->withLock($context, fn () => $store->complete(
                $context->identityHash,
                $owner,
                $stored,
                $completedAt,
                $expiresAt,
            ));

            if (! $saved) {
                throw new IdempotencyOwnershipLostException('The idempotency execution lost ownership before completion.');
            }

            $response->headers->set('Idempotency-Status', 'created');
            $this->events->dispatch(new IdempotencyRequestCompleted($context, $response->getStatusCode()));

            return $response;
        } catch (Throwable $e) {
            if ($e instanceof IdempotencyOwnershipLostException || $e instanceof ResponseNotReplayableException) {
                throw $e;
            }

            if ($this->isKnownSafeFailure($e)) {
                $this->withLock($context, fn () => $store->markFailedSafe(
                    $context->identityHash,
                    $owner,
                    $e::class,
                    new DateTimeImmutable(),
                ));
            } else {
                $this->markIndeterminate($context, $owner, $e::class);
            }

            throw $e;
        } finally {
            $current->clear();
        }
    }

    private function claimWithPolicy(IdempotencyContext $context): ClaimDecision
    {
        if ($context->profile->concurrentPolicy !== 'wait') {
            return $this->claim($context);
        }

        $deadline = microtime(true) + $context->profile->concurrentWaitSeconds;

        do {
            try {
                return $this->claim($context);
            } catch (ConcurrentIdempotencyRequestException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(50_000);
            }
        } while (true);
    }

    private function claim(IdempotencyContext $context): ClaimDecision
    {
        return $this->withLock($context, function () use ($context): ClaimDecision {
            $store = $this->stores->store($context->profile->store);
            $now = new DateTimeImmutable();
            $record = $store->find($context->identityHash);

            if ($record === null) {
                $owner = bin2hex(random_bytes(32));
                $record = $this->newRecord($context, $owner, $now);

                if (! $store->createProcessing($record)) {
                    $record = $store->find($context->identityHash);
                    if ($record === null) {
                        throw new IdempotencyStorageException('Failed to claim idempotency key.');
                    }
                    return $this->decideExisting($context, $record, $now);
                }

                $this->events->dispatch(new IdempotencyRequestClaimed($context, $record));
                return ClaimDecision::execute($record, $owner);
            }

            return $this->decideExisting($context, $record, $now);
        });
    }

    private function decideExisting(
        IdempotencyContext $context,
        IdempotencyRecord $record,
        DateTimeImmutable $now,
    ): ClaimDecision {
        $store = $this->stores->store($context->profile->store);

        if ($record->expiresAt <= $now && ! in_array($record->status, [IdempotencyStatus::Processing, IdempotencyStatus::Indeterminate], true)) {
            $store->forget($context->identityHash);
            $owner = bin2hex(random_bytes(32));
            $fresh = $this->newRecord($context, $owner, $now);

            if (! $store->createProcessing($fresh)) {
                throw new ConcurrentIdempotencyRequestException('The expired idempotency key was claimed concurrently.');
            }

            $this->events->dispatch(new IdempotencyRequestClaimed($context, $fresh));
            return ClaimDecision::execute($fresh, $owner);
        }

        if (! hash_equals($record->fingerprint, $context->fingerprint)) {
            $this->events->dispatch(new IdempotencyConflictDetected($context, 'fingerprint_mismatch'));
            throw new FingerprintConflictException('The idempotency key was already used with a different request fingerprint.');
        }

        if ($record->status === IdempotencyStatus::Completed) {
            return ClaimDecision::replay($record);
        }

        if ($record->status === IdempotencyStatus::Indeterminate) {
            throw new IdempotencyIndeterminateException('The previous execution has an indeterminate outcome and requires reconciliation.');
        }

        if ($record->status === IdempotencyStatus::FailedSafe) {
            return $this->reclaim($context, $record, $now, IdempotencyStatus::FailedSafe->value);
        }

        if ($record->status === IdempotencyStatus::Processing) {
            if (! $record->leaseExpired($now)) {
                throw new ConcurrentIdempotencyRequestException('An execution with this idempotency key is already in progress.');
            }

            if ($context->profile->orphanPolicy === 'safe_retry') {
                return $this->reclaim($context, $record, $now, IdempotencyStatus::Processing->value);
            }

            $store->markIndeterminate($context->identityHash, $record->ownerToken, 'processing_lease_expired', $now);
            $this->events->dispatch(new IdempotencyBecameIndeterminate($context, 'processing_lease_expired'));
            throw new IdempotencyIndeterminateException('The previous execution lease expired and its outcome is unknown.');
        }

        throw new IdempotencyStorageException('Unsupported idempotency record state.');
    }

    private function reclaim(
        IdempotencyContext $context,
        IdempotencyRecord $record,
        DateTimeImmutable $now,
        string $expectedStatus,
    ): ClaimDecision {
        $owner = bin2hex(random_bytes(32));
        $lease = $now->modify('+'.$context->profile->processingLeaseSeconds.' seconds');
        $expires = $now->modify('+'.$context->profile->ttlSeconds.' seconds');
        $store = $this->stores->store($context->profile->store);

        if (! $store->reclaim($context->identityHash, $expectedStatus, $owner, $now, $lease, $expires)) {
            throw new ConcurrentIdempotencyRequestException('The idempotency record changed while attempting to reclaim it.');
        }

        $updated = $store->find($context->identityHash);
        if ($updated === null) {
            throw new IdempotencyStorageException('Reclaimed idempotency record disappeared.');
        }

        $this->events->dispatch(new IdempotencyRequestClaimed($context, $updated));
        return ClaimDecision::execute($updated, $owner);
    }

    private function newRecord(IdempotencyContext $context, string $owner, DateTimeImmutable $now): IdempotencyRecord
    {
        return new IdempotencyRecord(
            id: (string) Str::ulid(),
            identityHash: $context->identityHash,
            keyHash: $context->keyHash,
            scopeHash: $context->scopeHash,
            fingerprint: $context->fingerprint,
            fingerprintVersion: $context->fingerprintVersion,
            status: IdempotencyStatus::Processing,
            profile: $context->profile->name,
            ownerToken: $owner,
            attempt: 1,
            replayCount: 0,
            requestMethod: explode(':', $context->operation, 2)[0],
            routeSignature: $context->operation,
            response: null,
            errorCode: null,
            metadata: ['profile' => $context->profile->name],
            startedAt: $now,
            leaseExpiresAt: $now->modify('+'.$context->profile->processingLeaseSeconds.' seconds'),
            completedAt: null,
            lastSeenAt: $now,
            expiresAt: $now->modify('+'.$context->profile->ttlSeconds.' seconds'),
        );
    }

    private function markIndeterminate(IdempotencyContext $context, string $owner, string $reason): void
    {
        $store = $this->stores->store($context->profile->store);
        $changed = $this->withLock($context, fn () => $store->markIndeterminate(
            $context->identityHash,
            $owner,
            $reason,
            new DateTimeImmutable(),
        ));

        if ($changed) {
            $this->events->dispatch(new IdempotencyBecameIndeterminate($context, $reason));
        }
    }

    private function isKnownSafeFailure(Throwable $e): bool
    {
        return $e instanceof SafeToRetryException
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException;
    }

    private function withLock(IdempotencyContext $context, callable $callback): mixed
    {
        return $this->locks->synchronized(
            $context->profile->lockStore,
            'idempotency:claim:'.$context->identityHash,
            $context->profile->lockSeconds,
            $context->profile->lockWaitSeconds,
            $callback(...),
        );
    }
}
