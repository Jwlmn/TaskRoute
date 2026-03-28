<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ($permissions === []) {
            return $next($request);
        }

        $granted = method_exists($user, 'resolvePermissions')
            ? $user->resolvePermissions()
            : (is_array($user->permissions ?? null) ? $user->permissions : []);
        $grantedMap = array_fill_keys($granted, true);

        foreach ($permissions as $permission) {
            if (isset($grantedMap[$permission])) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
