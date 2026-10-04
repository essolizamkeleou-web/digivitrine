<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role check: ->middleware('role:admin,moderator').
 * The role always comes from the database, never from the request. An unknown role name allows nobody.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_filter(array_map(fn (string $role) => UserRole::tryFrom($role), $roles));
        $user = $request->user();

        if ($user === null || ! in_array($user->role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
