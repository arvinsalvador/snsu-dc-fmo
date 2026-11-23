<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a suspended or deactivated account on its next request, reading the status
 * from the database each time so a change takes effect immediately.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = $user instanceof User ? User::query()->whereKey($user->getKey())->first(['id', 'status']) : null;

        if ($current?->status?->blocksSignIn()) {
            $message = $current->signInBlockMessage();
            if ($request->is('api/*')) {
                $user->tokens()->delete();

                return response()->json(['message' => $message], 403);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
