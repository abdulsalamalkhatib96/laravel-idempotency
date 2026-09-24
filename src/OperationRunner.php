<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Contracts\LockManager;
use Abdulsalam\LaravelIdempotency\Contracts\ResponseCodec;
use Abdulsalam\LaravelIdempotency\Data\ClaimDecision;
use Abdulsalam\LaravelIdempotency\Data\ClaimDecisionType;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\Exceptions\ConcurrentIdempotencyRequestException;
use Abdulsalam\LaravelIdempotency\Exceptions\FingerprintConflictException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyIndeterminateException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyOwnershipLostException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyStorageException;
use Abdulsalam\LaravelIdempotency\Exceptions\SafeToRetryException;
use Abdulsalam\LaravelIdempotency\Keys\KeyHasher;
use Abdulsalam\LaravelIdempotency\Keys\KeyValidator;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use Abdulsalam\LaravelIdempotency\Support\Canonicalizer;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

final class OperationRunner
{
    public function __construct(
        private readonly StoreManager $stores,
        private readonly LockManager $locks,
        private readonly ResponseCodec $codec,
        private readonly ProfileRepository $profiles,
        private readonly KeyHasher $hasher,
        private readonly KeyValidator $validator,
        private readonly Canonicalizer $canonicalizer,
        private readonly Container $container,
    ) {}

    public function run(
        string $scope,
        string $key,
        mixed $payload,
        callable $callback,
        ?string $profileName = null,
    ): mixed {
        $this->validator->validate($key);
        $context = $this->context($scope, $key, $payload, $profileName);
        $store = $this->stores->store($context->profile->store);
        $decision = $this->claimWithPolicy($context);

        if ($decision->type === ClaimDecisionType::Replay) {
            if ($decision->record->response === null) {
                throw new IdempotencyStorageException('Completed operation has no stored response.');
            }

            $store->incrementReplay($context->identityHash, new DateTimeImmutable());
            $current = $this->container->make(CurrentIdempotencyContext::class);
            $current->set($context, true);

            try {
                $response = $this->codec->decode($decision->record->response, $context->profile);
                $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                return $decoded['value'] ?? null;
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
            $value = $callback();
            $response = new JsonResponse(['value' => $value]);
            $normalized = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $normalizedValue = $normalized['value'] ?? null;
            $stored = $this->codec->encode($response, $context->profile);
            $completedAt = new DateTimeImmutable();
            $saved = $this->locked($context, fn () => $store->complete(
                $context->identityHash,
                $owner,
                $stored,
                $completedAt,
                $completedAt->modify('+'.$context->profile->ttlSeconds.' seconds'),
            ));

            if (! $saved) {
                throw new IdempotencyOwnershipLostException('The operation lost idempotency ownership before completion.');
            }

            return $normalizedValue;
        } catch (Throwable $e) {
            if ($e instanceof IdempotencyOwnershipLostException) {
                throw $e;
            }

            $this->locked($context, function () use ($store, $context, $owner, $e): void {
                if ($e instanceof SafeToRetryException) {
                    $store->markFailedSafe($context->identityHash, $owner, $e::class, new DateTimeImmutable());
                } else {
                    $store->markIndeterminate($context->identityHash, $owner, $e::class, new DateTimeImmutable());
                }
            });

            throw $e;
        } finally {
            $current->clear();
        }
    }

    private function context(string $scope, string $key, mixed $payload, ?string $profileName): IdempotencyContext
    {
        $profile = $this->profiles->get($profileName);
        $namespace = (string) config('idempotency.namespace', config('app.name', 'laravel'));
        $scopeValue = 'namespace:'.$namespace.'|service:'.$scope;
        $scopeHash = hash('sha256', $scopeValue);
        $version = (string) config('idempotency.fingerprint_version', 'v1');

        return new IdempotencyContext(
            key: $key,
            keyHash: $this->hasher->hash($key),
            scope: $scopeValue,
            scopeHash: $scopeHash,
            identityHash: $this->hasher->hash($scopeHash.'|'.$key),
            fingerprint: hash('sha256', $version.'|'.$this->canonicalizer->json($payload)),
            fingerprintVersion: $version,
            principal: null,
            operation: 'SERVICE:'.$scope,
            profile: $profile,
        );
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
        return $this->locked($context, function () use ($context): ClaimDecision {
            $store = $this->stores->store($context->profile->store);
            $now = new DateTimeImmutable();
            $record = $store->find($context->identityHash);

            if ($record === null) {
                return $this->createClaim($context, $now);
            }

            if ($record->expiresAt <= $now && ! in_array($record->status, [IdempotencyStatus::Processing, IdempotencyStatus::Indeterminate], true)) {
                $store->forget($context->identityHash);
                return $this->createClaim($context, $now);
            }

            if (! hash_equals($record->fingerprint, $context->fingerprint)) {
                throw new FingerprintConflictException('The idempotency key was already used with a different operation payload.');
            }

            if ($record->status === IdempotencyStatus::Completed) {
                return ClaimDecision::replay($record);
            }

            if ($record->status === IdempotencyStatus::Indeterminate) {
                throw new IdempotencyIndeterminateException('The previous operation outcome is indeterminate.');
            }

            if ($record->status === IdempotencyStatus::FailedSafe) {
                return $this->reclaim($context, $record, $now, IdempotencyStatus::FailedSafe->value);
            }

            if ($record->status === IdempotencyStatus::Processing) {
                if (! $record->leaseExpired($now)) {
                    throw new ConcurrentIdempotencyRequestException('This operation is already in progress.');
                }

                if ($context->profile->orphanPolicy !== 'safe_retry') {
                    $store->markIndeterminate(
                        $context->identityHash,
                        $record->ownerToken,
                        'processing_lease_expired',
                        $now,
                    );
                    throw new IdempotencyIndeterminateException('The previous operation lease expired and its outcome is unknown.');
                }

                return $this->reclaim($context, $record, $now, IdempotencyStatus::Processing->value);
            }

            throw new IdempotencyStorageException('Unsupported idempotency record state.');
        });
    }

    private function createClaim(IdempotencyContext $context, DateTimeImmutable $now): ClaimDecision
    {
        $store = $this->stores->store($context->profile->store);
        $owner = bin2hex(random_bytes(32));
        $record = new IdempotencyRecord(
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
            requestMethod: 'SERVICE',
            routeSignature: $context->operation,
            response: null,
            errorCode: null,
            metadata: [],
            startedAt: $now,
            leaseExpiresAt: $now->modify('+'.$context->profile->processingLeaseSeconds.' seconds'),
            completedAt: null,
            lastSeenAt: $now,
            expiresAt: $now->modify('+'.$context->profile->ttlSeconds.' seconds'),
        );

        if (! $store->createProcessing($record)) {
            throw new ConcurrentIdempotencyRequestException('The operation was claimed concurrently.');
        }

        return ClaimDecision::execute($record, $owner);
    }

    private function reclaim(
        IdempotencyContext $context,
        IdempotencyRecord $record,
        DateTimeImmutable $now,
        string $expectedStatus,
    ): ClaimDecision {
        $store = $this->stores->store($context->profile->store);
        $owner = bin2hex(random_bytes(32));

        if (! $store->reclaim(
            $context->identityHash,
            $expectedStatus,
            $owner,
            $now,
            $now->modify('+'.$context->profile->processingLeaseSeconds.' seconds'),
            $now->modify('+'.$context->profile->ttlSeconds.' seconds'),
        )) {
            throw new ConcurrentIdempotencyRequestException('The operation claim changed concurrently.');
        }

        $updated = $store->find($context->identityHash);
        if ($updated === null) {
            throw new IdempotencyStorageException('Reclaimed operation record disappeared.');
        }

        return ClaimDecision::execute($updated, $owner);
    }

    private function locked(IdempotencyContext $context, callable $callback): mixed
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
