<?php

namespace Abdulsalam\LaravelIdempotency\Response;

use Abdulsalam\LaravelIdempotency\Contracts\ResponseCodec;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyProfile;
use Abdulsalam\LaravelIdempotency\Data\StoredResponse;
use Abdulsalam\LaravelIdempotency\Exceptions\ResponseNotReplayableException;
use Illuminate\Contracts\Encryption\Encrypter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelResponseCodec implements ResponseCodec
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function encode(Response $response, IdempotencyProfile $profile): StoredResponse
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            throw new ResponseNotReplayableException('Streamed and binary responses cannot be replayed safely.');
        }

        $body = (string) $response->getContent();
        $size = strlen($body);

        if ($size > $profile->maxResponseBytes) {
            throw new ResponseNotReplayableException(
                "Response size {$size} exceeds profile limit {$profile->maxResponseBytes} bytes.",
            );
        }

        $encoding = $profile->encryptResponse ? 'encrypted' : 'plain';
        $storedBody = $profile->encryptResponse ? $this->encrypter->encryptString($body) : $body;

        return new StoredResponse(
            status: $response->getStatusCode(),
            headers: $this->headers($response),
            body: $storedBody,
            encoding: $encoding,
            checksum: hash('sha256', $storedBody),
            size: $size,
        );
    }

    public function decode(StoredResponse $stored, IdempotencyProfile $profile): Response
    {
        if (! hash_equals($stored->checksum, hash('sha256', $stored->body))) {
            throw new ResponseNotReplayableException('Stored idempotency response checksum mismatch.');
        }

        $body = match ($stored->encoding) {
            'plain' => $stored->body,
            'encrypted' => $this->encrypter->decryptString($stored->body),
            default => throw new ResponseNotReplayableException("Unsupported stored response encoding [{$stored->encoding}]."),
        };

        $response = new Response($body, $stored->status);

        foreach ($stored->headers as $name => $values) {
            $response->headers->set($name, $values, true);
        }

        $response->headers->set('Idempotency-Replayed', 'true');
        $response->headers->set('Idempotency-Status', 'replayed');

        return $response;
    }

    private function headers(Response $response): array
    {
        $whitelist = array_map('strtolower', (array) config('idempotency.response.header_whitelist', []));
        $result = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $whitelist, true)) {
                $result[$name] = array_values($values);
            }
        }

        return $result;
    }
}
