<?php

namespace Abdulsalam\LaravelIdempotency\Storage;

use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyStore;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;
use Closure;
use Illuminate\Contracts\Container\Container;

final class StoreManager
{
    /** @var array<string, Closure(Container): IdempotencyStore> */
    private array $extensions = [];

    public function __construct(private readonly Container $container) {}

    public function extend(string $name, Closure $factory): void
    {
        $this->extensions[$name] = $factory;
    }

    public function store(string $name): IdempotencyStore
    {
        if (isset($this->extensions[$name])) {
            $store = ($this->extensions[$name])($this->container);

            if (! $store instanceof IdempotencyStore) {
                throw new InvalidIdempotencyConfigurationException(
                    "Custom idempotency store [{$name}] must implement ".IdempotencyStore::class.'.',
                );
            }

            return $store;
        }

        return match ($name) {
            'database' => $this->container->make(DatabaseIdempotencyStore::class),
            'redis' => $this->container->make(RedisIdempotencyStore::class),
            default => throw new InvalidIdempotencyConfigurationException("Unknown idempotency store [{$name}]."),
        };
    }
}
