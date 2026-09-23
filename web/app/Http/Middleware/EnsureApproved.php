<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->fresh()?->isApproved()) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Account is not approved.'], 403);
            }

            return redirect()->route('registration.status');
        }

        return $next($request);
    }
}
