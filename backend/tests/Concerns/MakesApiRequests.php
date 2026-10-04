<?php

namespace Tests\Concerns;

use App\Models\User;

trait MakesApiRequests
{
    /**
     * Forgets any token and any cached guard: the Laravel test application keeps its guards
     * between two requests of the same test, which would hide a revoked or missing token.
     */
    protected function asGuest(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function withApiToken(string $token): static
    {
        $this->asGuest();

        return $this->withToken($token);
    }

    protected function tokenFor(User $user, string $name = 'test'): string
    {
        return $user->createToken($name)->plainTextToken;
    }
}
