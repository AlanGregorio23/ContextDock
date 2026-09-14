<?php

namespace App\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContextDockOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowed = array_map(
            fn (string $value): string => rtrim($value, '/'),
            array_merge([(string) config('app.url')], config('contextdock.mcp.allowed_origins', [])),
        );

        if ($origin !== null && ! in_array($origin, $allowed, true)) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        return $next($request);
    }
}
