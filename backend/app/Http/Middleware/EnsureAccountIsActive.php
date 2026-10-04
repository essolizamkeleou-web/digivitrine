<?php

namespace App\Http\Middleware;

use App\Support\ApiMessages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a deactivated account even when its token is still valid.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            return response()->json(['message' => ApiMessages::ACCOUNT_DISABLED], 403);
        }

        return $next($request);
    }
}
