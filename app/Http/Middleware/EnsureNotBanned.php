<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Banned accounts can only read why, appeal, sign out, or delete the account.
 */
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $request->routeIs('banned', 'logout', 'appeals.create', 'profile.edit', 'livewire.*') && $user->activeBan()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This account is banned.'], 403)
                : redirect()->route('banned');
        }

        return $next($request);
    }
}
