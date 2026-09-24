<?php

namespace Abdulsalam\LaravelIdempotency\Http\Middleware;

use Abdulsalam\LaravelIdempotency\ContextFactory;
use Abdulsalam\LaravelIdempotency\Exceptions\IdempotencyException;
use Abdulsalam\LaravelIdempotency\Http\ProblemDetailsResponseFactory;
use Abdulsalam\LaravelIdempotency\IdempotencyEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIdempotency
{
    public function __construct(
        private readonly ContextFactory $contexts,
        private readonly IdempotencyEngine $engine,
        private readonly ProblemDetailsResponseFactory $problems,
    ) {}

    public function handle(Request $request, Closure $next, ?string $profile = null): Response
    {
        try {
            $context = $this->contexts->fromRequest($request, $profile);
            return $this->engine->execute($context, fn (): Response => $next($request));
        } catch (IdempotencyException $e) {
            return $this->problems->make($e);
        }
    }
}
