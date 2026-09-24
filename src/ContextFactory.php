<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Contracts\IdempotencyKeyResolver;
use Abdulsalam\LaravelIdempotency\Contracts\PrincipalResolver;
use Abdulsalam\LaravelIdempotency\Contracts\RequestFingerprinter;
use Abdulsalam\LaravelIdempotency\Contracts\ScopeResolver;
use Abdulsalam\LaravelIdempotency\Contracts\TenantResolver;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyKeyException;
use Abdulsalam\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;
use Abdulsalam\LaravelIdempotency\Exceptions\PrincipalRequiredException;
use Abdulsalam\LaravelIdempotency\Keys\KeyHasher;
use Abdulsalam\LaravelIdempotency\Keys\KeyValidator;
use Illuminate\Http\Request;

final class ContextFactory
{
    public function __construct(
        private readonly IdempotencyKeyResolver $keyResolver,
        private readonly PrincipalResolver $principalResolver,
        private readonly TenantResolver $tenantResolver,
        private readonly ScopeResolver $scopeResolver,
        private readonly RequestFingerprinter $fingerprinter,
        private readonly KeyHasher $hasher,
        private readonly KeyValidator $validator,
        private readonly ProfileRepository $profiles,
    ) {}

    public function fromRequest(Request $request, ?string $profileName = null): IdempotencyContext
    {
        $profile = $this->profiles->get($profileName);
        $key = $this->keyResolver->resolve($request);

        if ($key === null || $key === '') {
            throw new MissingIdempotencyKeyException('Idempotency-Key header is required.');
        }

        $this->validator->validate($key);

        $principal = $this->principalResolver->resolve($request);
        if ($profile->requirePrincipal && $principal === null) {
            throw new PrincipalRequiredException('This idempotency profile requires an authenticated principal.');
        }

        $tenant = $this->tenantResolver->resolve($request);
        $operation = $this->scopeResolver->operation($request);
        $scope = $this->scopeResolver->resolve($request, $principal, $tenant);
        $keyHash = $this->hasher->hash($key);
        $scopeHash = hash('sha256', $scope);
        $identityHash = $this->hasher->hash($scopeHash.'|'.$key);

        return new IdempotencyContext(
            key: $key,
            keyHash: $keyHash,
            scope: $scope,
            scopeHash: $scopeHash,
            identityHash: $identityHash,
            fingerprint: $this->fingerprinter->fingerprint($request, $operation),
            fingerprintVersion: (string) config('idempotency.fingerprint_version', 'v1'),
            principal: $principal,
            operation: $operation,
            profile: $profile,
        );
    }

}
