<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesViewer
{
    /**
     * The authenticated, active user (a bearer token is optional on public routes), or null for a guest.
     */
    protected function viewer(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return ($user instanceof User && $user->is_active) ? $user : null;
    }
}
