<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const LOGOUT = '/api/v1/auth/logout';

    private const ME = '/api/v1/auth/me';

    public function test_logout_revokes_the_token_and_returns_no_content(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withApiToken($token)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_revoked_token_can_no_longer_be_used(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withApiToken($token)->getJson(self::ME)->assertOk();
        $this->withApiToken($token)->postJson(self::LOGOUT)->assertNoContent();

        $this->withApiToken($token)
            ->getJson(self::ME)
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::UNAUTHENTICATED]);
    }

    public function test_logout_only_revokes_the_current_device(): void
    {
        $user = User::factory()->create();
        $android = $this->tokenFor($user, 'android');
        $windows = $this->tokenFor($user, 'windows');

        $this->withApiToken($android)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertSame('windows', PersonalAccessToken::firstOrFail()->name);

        $this->withApiToken($windows)->getJson(self::ME)->assertOk();
        $this->withApiToken($android)->getJson(self::ME)->assertUnauthorized();
    }

    public function test_logout_without_a_token_is_refused(): void
    {
        $this->asGuest()->postJson(self::LOGOUT)
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::UNAUTHENTICATED]);
    }

    public function test_logging_out_twice_with_the_same_token_is_refused(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->withApiToken($token)->postJson(self::LOGOUT)->assertNoContent();
        $this->withApiToken($token)->postJson(self::LOGOUT)->assertUnauthorized();
    }

    public function test_a_full_session_registers_logs_in_and_logs_out(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ama Test',
            'phone' => '92 34 56 78',
            'password' => 'mot-de-passe-1',
            'password_confirmation' => 'mot-de-passe-1',
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', ['login' => '92345678', 'password' => 'mot-de-passe-1'])
            ->assertOk();

        $this->assertNotSame($register->json('data.token'), $login->json('data.token'));

        $this->withApiToken($login->json('data.token'))->postJson(self::LOGOUT)->assertNoContent();

        // The token issued at registration (another device) still works.
        $this->withApiToken($register->json('data.token'))->getJson(self::ME)->assertOk();
    }
}
