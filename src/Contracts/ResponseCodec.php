<?php

namespace Abdulsalam\LaravelIdempotency\Contracts;

use Abdulsalam\LaravelIdempotency\Data\IdempotencyProfile;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use Symfony\Component\HttpFoundation\Response;

interface ResponseCodec
{
    public function encode(Response $response, IdempotencyProfile $profile): StoredResponse;

    public function decode(StoredResponse $stored, IdempotencyProfile $profile): Response;
}
