<?php

namespace Abdulsalam\LaravelIdempotency\Scope;

use Abdulsalam\LaravelIdempotency\Contracts\ScopeResolver;
use Illuminate\Http\Request;

final class DefaultScopeResolver implements ScopeResolver
{
    public function resolve(Request $request, ?string $principal, ?string $tenant): string
    {
        return implode('|', [
            'namespace:'.(string) config('idempotency.namespace', config('app.name', 'laravel')),
            'tenant:'.($tenant ?? '-'),
            'principal:'.($principal ?? 'guest'),
            'operation:'.$this->operation($request),
        ]);
    }

    public function operation(Request $request): string
    {
        $route = $request->route();
        $name = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
        $uri = is_object($route) && method_exists($route, 'uri') ? $route->uri() : $request->path();

        return strtoupper($request->method()).':'.($name ?: $uri);
    }
}
