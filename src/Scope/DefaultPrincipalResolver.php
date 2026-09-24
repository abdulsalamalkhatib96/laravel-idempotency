<?php

namespace Abdulsalam\LaravelIdempotency\Scope;

use Abdulsalam\LaravelIdempotency\Contracts\PrincipalResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class DefaultPrincipalResolver implements PrincipalResolver
{
    public function resolve(Request $request): ?string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            $id = $user->getAuthIdentifier();
            return $id === null ? null : get_class($user).':'.(string) $id;
        }

        return null;
    }
}
