<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin API checks the token owner's staff role against the path (see Roles::API).
 */
class StaffRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = (string) str($request->path())->after('api/admin/');
        abort_unless($request->user()?->hasRole(Roles::forApiPath($path)), 403, 'Your staff role doesn’t include this.');

        return $next($request);
    }
}
