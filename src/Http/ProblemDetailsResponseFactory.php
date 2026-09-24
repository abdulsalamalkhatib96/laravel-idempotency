<?php

namespace Abdulsalam\LaravelIdempotency\Http;

use Abdulsalam\LaravelIdempotency\Exceptions\ConcurrentIdempotencyRequestException;
use Abdulsalam\LaravelIdempotency\Exceptions\FingerprintConflictException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyIndeterminateException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyLockException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyOwnershipLostException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyStorageException;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyConfigurationException;
use Abdulsalam\LaravelIdempotency\Exceptions\InvalidIdempotencyKeyException;
use Abdulsalam\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;
use Abdulsalam\LaravelIdempotency\Exceptions\PrincipalRequiredException;
use Abdulsalam\LaravelIdempotency\Exceptions\ResponseNotReplayableException;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyException;
use Illuminate\Http\JsonResponse;

final class ProblemDetailsResponseFactory
{
    public function make(IdempotencyException $e): JsonResponse
    {
        [$status, $type, $title] = match (true) {
            $e instanceof MissingIdempotencyKeyException => [400, 'idempotency_key_missing', 'Idempotency key is required'],
            $e instanceof InvalidIdempotencyKeyException => [400, 'idempotency_key_invalid', 'Idempotency key is invalid'],
            $e instanceof PrincipalRequiredException => [401, 'idempotency_principal_required', 'Authenticated principal is required'],
            $e instanceof FingerprintConflictException => [422, 'idempotency_fingerprint_conflict', 'Idempotency key was reused with a different request'],
            $e instanceof ConcurrentIdempotencyRequestException => [409, 'idempotency_request_in_progress', 'An equivalent request is already in progress'],
            $e instanceof IdempotencyIndeterminateException => [409, 'idempotency_result_indeterminate', 'The previous execution has an unknown outcome'],
            $e instanceof IdempotencyOwnershipLostException => [409, 'idempotency_ownership_lost', 'Execution ownership was lost'],
            $e instanceof ResponseNotReplayableException => [409, 'idempotency_response_not_replayable', 'The operation response cannot be safely replayed'],
            $e instanceof IdempotencyLockException => [503, 'idempotency_lock_unavailable', 'Idempotency lock is unavailable'],
            $e instanceof IdempotencyStorageException => [503, 'idempotency_storage_unavailable', 'Idempotency storage is unavailable'],
            $e instanceof InvalidIdempotencyConfigurationException => [500, 'idempotency_configuration_error', 'Idempotency is misconfigured'],
            default => [500, 'idempotency_error', 'Idempotency processing failed'],
        };

        $response = new JsonResponse([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $e->getMessage(),
        ], $status, ['Content-Type' => 'application/problem+json']);

        if ($e instanceof ConcurrentIdempotencyRequestException) {
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}
