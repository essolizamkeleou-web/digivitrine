<?php

namespace Tests\Feature\Api;

use App\Models\Artisan;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const URL = '/api/v1/auth/me';

    public function test_authenticated_user_gets_exactly_the_public_fields(): void
    {
        $user = User::factory()->create(['phone' => '+22890123456']);

        $response = $this->withApiToken($this->tokenFor($user))->getJson(self::URL);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $user->fresh()->uuid)
            ->assertJsonPath('data.role', 'artisan')
            ->assertJsonPath('data.phone', '+22890123456')
            ->assertJsonPath('data.artisan', null);

        $this->assertEqualsCanonicalizing(
            ['uuid', 'name', 'email', 'phone', 'role', 'is_active', 'email_verified_at', 'created_at', 'artisan'],
            array_keys($response->json('data'))
        );
    }

    public function test_sensitive_data_and_tokens_are_never_exposed(): void
    {
        $user = User::factory()->create();

        $response = $this->withApiToken($this->tokenFor($user))->getJson(self::URL)->assertOk();

        foreach (['id', 'password', 'remember_token', 'token', 'tokens', 'deleted_at'] as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }

        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    public function test_the_profile_summary_is_included_when_the_user_owns_one(): void
    {
        $user = User::factory()->create();
        $artisan = Artisan::factory()->ownedBy($user)->create();

        $response = $this->withApiToken($this->tokenFor($user))->getJson(self::URL)->assertOk();

        $this->assertEqualsCanonicalizing(['uuid', 'business_name', 'status'], array_keys($response->json('data.artisan')));
        $response->assertJsonPath('data.artisan.uuid', $artisan->fresh()->uuid)
            ->assertJsonPath('data.artisan.status', 'draft');
    }

    public function test_the_staff_role_is_reported_as_stored(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withApiToken($this->tokenFor($admin))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_a_request_without_token_is_refused_with_json_even_without_the_accept_header(): void
    {
        $this->asGuest()->get(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::UNAUTHENTICATED]);
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $this->withApiToken('1|not-a-real-token')
            ->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::UNAUTHENTICATED]);
    }

    public function test_a_deactivated_account_is_refused_even_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $user->forceFill(['is_active' => false])->save();

        $this->withApiToken($token)
            ->getJson(self::URL)
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);
    }

    public function test_tokens_expire_after_ninety_days(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(89)->days();
        $this->withApiToken($token)->getJson(self::URL)->assertOk();

        $this->travel(2)->days();
        $this->withApiToken($token)->getJson(self::URL)->assertUnauthorized();
    }

    public function test_me_does_not_create_or_change_tokens(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withApiToken($token)->getJson(self::URL)->assertOk();

        $this->assertSame(1, PersonalAccessToken::count());
    }
}
