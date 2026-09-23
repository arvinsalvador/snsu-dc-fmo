<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();
        $token = $rawToken ? PersonalAccessToken::findToken($rawToken) : null;

        if (! $token || ! $token->tokenable?->is($request->user()) ||
            ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->user()->withAccessToken($token);

        return $next($request);
    }
}
