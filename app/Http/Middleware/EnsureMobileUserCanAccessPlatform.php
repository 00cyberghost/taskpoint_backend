<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileUserCanAccessPlatform
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Authentication is required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (in_array($user->status, ['suspended', 'banned'], true)) {
            return response()->json([
                'message' => 'This account has been suspended. Please contact support.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email address before using the platform.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
