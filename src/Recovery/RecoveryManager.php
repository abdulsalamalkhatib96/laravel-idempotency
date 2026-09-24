<?php

namespace Abdulsalam\LaravelIdempotency\Recovery;

use Abdulsalam\LaravelIdempotency\Contracts\RecoveryResolver;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;
use Illuminate\Support\Str;

final class RecoveryManager
{
    /** @var array<string, RecoveryResolver> */
    private array $resolvers = [];

    /**
     * Register an exact operation or a Laravel-style wildcard pattern, e.g. SERVICE:withdrawal:*.
     */
    public function register(string $operationPattern, RecoveryResolver $resolver): void
    {
        $this->resolvers[$operationPattern] = $resolver;
    }

    public function has(string $operation): bool
    {
        return $this->resolverFor($operation) !== null;
    }

    public function recover(IdempotencyRecord $record): RecoveryResult
    {
        $resolver = $this->resolverFor($record->routeSignature);

        if ($resolver === null) {
            throw new InvalidIdempotencyConfigurationException(
                "No recovery resolver registered for [{$record->routeSignature}].",
            );
        }

        return $resolver->recover($record);
    }

    private function resolverFor(string $operation): ?RecoveryResolver
    {
        if (isset($this->resolvers[$operation])) {
            return $this->resolvers[$operation];
        }

        foreach ($this->resolvers as $pattern => $resolver) {
            if (Str::is($pattern, $operation)) {
                return $resolver;
            }
        }

        return null;
    }
}
