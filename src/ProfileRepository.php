<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyProfile;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;

final class ProfileRepository
{
    public function get(?string $name = null): IdempotencyProfile
    {
        $name ??= (string) config('idempotency.default_profile', 'default');
        $config = config("idempotency.profiles.{$name}");

        if (! is_array($config)) {
            throw new InvalidIdempotencyConfigurationException("Idempotency profile [{$name}] is not configured.");
        }

        return IdempotencyProfile::fromArray($name, $config);
    }
}
