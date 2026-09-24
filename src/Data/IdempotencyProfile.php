<?php

namespace Abdulsalam\LaravelIdempotency\Data;

use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;

final readonly class IdempotencyProfile
{
    public function __construct(
        public string $name,
        public string $store,
        public string $lockStore,
        public int $ttlSeconds,
        public int $processingLeaseSeconds,
        public int $lockSeconds,
        public int $lockWaitSeconds,
        public string $concurrentPolicy,
        public int $concurrentWaitSeconds,
        public string $orphanPolicy,
        public bool $requirePrincipal,
        public bool $encryptResponse,
        public int $maxResponseBytes,
    ) {
        if (! in_array($this->concurrentPolicy, ['conflict', 'wait'], true)) {
            throw new InvalidIdempotencyConfigurationException("Invalid concurrent policy [{$this->concurrentPolicy}].");
        }

        if (! in_array($this->orphanPolicy, ['indeterminate', 'safe_retry'], true)) {
            throw new InvalidIdempotencyConfigurationException("Invalid orphan policy [{$this->orphanPolicy}].");
        }

        if ($this->store === '' || $this->lockStore === '') {
            throw new InvalidIdempotencyConfigurationException('Idempotency store and lock_store must be non-empty.');
        }

        foreach ([
            'ttlSeconds' => $this->ttlSeconds,
            'processingLeaseSeconds' => $this->processingLeaseSeconds,
            'lockSeconds' => $this->lockSeconds,
            'maxResponseBytes' => $this->maxResponseBytes,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidIdempotencyConfigurationException("{$name} must be greater than zero.");
            }
        }

        foreach ([
            'lockWaitSeconds' => $this->lockWaitSeconds,
            'concurrentWaitSeconds' => $this->concurrentWaitSeconds,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidIdempotencyConfigurationException("{$name} cannot be negative.");
            }
        }
    }

    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            store: (string) ($config['store'] ?? 'database'),
            lockStore: (string) ($config['lock_store'] ?? 'database'),
            ttlSeconds: (int) ($config['ttl'] ?? 86_400),
            processingLeaseSeconds: (int) ($config['processing_lease'] ?? 120),
            lockSeconds: (int) ($config['lock_seconds'] ?? 10),
            lockWaitSeconds: (int) ($config['lock_wait_seconds'] ?? 3),
            concurrentPolicy: (string) ($config['concurrent'] ?? 'conflict'),
            concurrentWaitSeconds: (int) ($config['concurrent_wait_seconds'] ?? 3),
            orphanPolicy: (string) ($config['orphan_policy'] ?? 'indeterminate'),
            requirePrincipal: (bool) ($config['require_principal'] ?? false),
            encryptResponse: (bool) ($config['encrypt_response'] ?? false),
            maxResponseBytes: (int) ($config['max_response_bytes'] ?? 2_097_152),
        );
    }
}
