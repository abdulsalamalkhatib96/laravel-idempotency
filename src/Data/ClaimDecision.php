<?php

namespace Abdulsalam\LaravelIdempotency\Data;

final readonly class ClaimDecision
{
    private function __construct(
        public ClaimDecisionType $type,
        public IdempotencyRecord $record,
        public ?string $ownerToken = null,
    ) {}

    public static function execute(IdempotencyRecord $record, string $ownerToken): self
    {
        return new self(ClaimDecisionType::Execute, $record, $ownerToken);
    }

    public static function replay(IdempotencyRecord $record): self
    {
        return new self(ClaimDecisionType::Replay, $record);
    }
}
