<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidContextToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (method_exists($user, 'currentAccessToken') && $token = $user->currentAccessToken()) {
            if (! $user->tokenCan('context:read') && ! $user->tokenCan('context:write') && ! $user->tokenCan('*')) {
                return response()->json(['message' => 'Token lacks required context abilities.'], 403);
            }
        }

        return $next($request);
    }
}
