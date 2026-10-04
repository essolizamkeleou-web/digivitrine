<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated(), $request->validated('device_name'));

        return $this->tokenResponse($result['user'], $result['token'], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->authenticate($request->validated('login'), $request->validated('password'));

        if ($user === null) {
            return response()->json(['message' => ApiMessages::INVALID_CREDENTIALS], 401);
        }

        // Only revealed after a successful authentication.
        if (! $user->is_active) {
            return response()->json(['message' => ApiMessages::ACCOUNT_DISABLED], 403);
        }

        return $this->tokenResponse($user, $this->auth->issueToken($user, $request->validated('device_name')), 200);
    }

    /**
     * Revokes the token used for this request only (the other devices stay connected).
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('artisan'));
    }

    private function tokenResponse(User $user, string $token, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => UserResource::make($user)->resolve(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], $status);
    }
}
