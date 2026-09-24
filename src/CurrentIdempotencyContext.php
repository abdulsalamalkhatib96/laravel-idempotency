<?php

namespace Abdulsalam\LaravelIdempotency;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyContext;

final class CurrentIdempotencyContext
{
    private ?IdempotencyContext $context = null;
    private bool $replay = false;
    private ?string $ownerToken = null;

    public function set(IdempotencyContext $context, bool $replay = false, ?string $ownerToken = null): void
    {
        $this->context = $context;
        $this->replay = $replay;
        $this->ownerToken = $ownerToken;
    }

    public function clear(): void
    {
        $this->context = null;
        $this->replay = false;
        $this->ownerToken = null;
    }

    public function get(): ?IdempotencyContext
    {
        return $this->context;
    }

    public function isReplay(): bool
    {
        return $this->replay;
    }

    public function ownerToken(): ?string
    {
        return $this->ownerToken;
    }
}
