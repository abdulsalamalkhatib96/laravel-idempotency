<?php

namespace Abdulsalam\LaravelIdempotency\Data;

enum ClaimDecisionType: string
{
    case Execute = 'execute';
    case Replay = 'replay';
}
