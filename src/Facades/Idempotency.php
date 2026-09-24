<?php

namespace Abdulsalam\LaravelIdempotency\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Abdulsalam\LaravelIdempotency\Data\IdempotencyContext|null current()
 * @method static bool isReplay()
 * @method static mixed run(string $scope, string $key, mixed $payload, callable $callback, ?string $profile = null)
 * @method static void remember(array $metadata)
 * @method static void reference(string $reference)
 * @method static string boundaryKey(string $boundary)
 * @method static string boundaryKeyForRecord(\Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord $record, string $boundary)
 */
final class Idempotency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abdulsalam\LaravelIdempotency\IdempotencyManager::class;
    }
}
