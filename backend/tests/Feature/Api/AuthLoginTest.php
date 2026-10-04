<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const URL = '/api/v1/auth/login';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Kofi Mensah',
            'phone' => '+22890123456',
            'email' => 'kofi@example.com',
            'password' => 'mot-de-passe-1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function credentials(array $overrides = []): array
    {
        return array_merge([
            'login' => '90 12 34 56',
            'password' => 'mot-de-passe-1',
            'device_name' => 'Windows PC',
        ], $overrides);
    }

    public function test_login_with_the_phone_returns_a_token_and_the_public_user(): void
    {
        $this->postJson(self::URL, $this->credentials())
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'user' => ['uuid', 'name', 'email', 'phone', 'role', 'is_active', 'email_verified_at', 'created_at'],
                'token',
                'token_type',
            ]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.uuid', $this->user->fresh()->uuid)
            ->assertJsonPath('data.user.phone', '+22890123456');

        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertSame('Windows PC', PersonalAccessToken::firstOrFail()->name);
    }

    public function test_every_phone_format_and_the_email_are_accepted(): void
    {
        foreach (['+22890123456', '+228 90 12 34 56', '00228 90123456', 'kofi@example.com', ' Kofi@Example.COM '] as $login) {
            $this->postJson(self::URL, $this->credentials(['login' => $login]))->assertOk();
        }

        $this->assertSame(5, PersonalAccessToken::count());
    }

    public function test_the_issued_token_authenticates_the_user(): void
    {
        $token = $this->postJson(self::URL, $this->credentials())->json('data.token');

        $this->withApiToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->user->fresh()->uuid);
    }

    public function test_the_response_never_exposes_sensitive_data(): void
    {
        $content = $this->postJson(self::URL, $this->credentials())->assertOk()->getContent();

        $this->assertStringNotContainsString('mot-de-passe-1', $content);
        $this->assertStringNotContainsString('"password"', $content);
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString('$2y$', $content);
    }

    public function test_each_login_creates_a_separate_token_for_each_device(): void
    {
        $first = $this->postJson(self::URL, $this->credentials(['device_name' => 'Android']))->json('data.token');
        $second = $this->postJson(self::URL, $this->credentials(['device_name' => 'Windows']))->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, PersonalAccessToken::count());
        $this->assertEqualsCanonicalizing(['Android', 'Windows'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_a_wrong_password_is_refused_without_creating_a_token(): void
    {
        $this->postJson(self::URL, $this->credentials(['password' => 'mauvais-mot-de-passe']))
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::INVALID_CREDENTIALS]);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_unknown_account_gets_the_same_answer_as_a_wrong_password(): void
    {
        $wrongPassword = $this->postJson(self::URL, $this->credentials(['password' => 'mauvais']));
        $unknown = $this->postJson(self::URL, $this->credentials(['login' => '99 99 99 99', 'password' => 'mauvais']));

        $wrongPassword->assertUnauthorized();
        $unknown->assertUnauthorized();

        $this->assertSame($wrongPassword->json(), $unknown->json());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_invalid_phone_format_is_treated_as_unknown_credentials(): void
    {
        $this->postJson(self::URL, $this->credentials(['login' => 'abc']))
            ->assertUnauthorized()
            ->assertExactJson(['message' => ApiMessages::INVALID_CREDENTIALS]);
    }

    public function test_a_deactivated_account_is_refused_only_after_a_correct_password(): void
    {
        User::factory()->inactive()->create(['phone' => '+22891111111', 'password' => 'mot-de-passe-1']);

        $this->postJson(self::URL, $this->credentials(['login' => '91 11 11 11']))
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);

        $this->postJson(self::URL, $this->credentials(['login' => '91 11 11 11', 'password' => 'mauvais']))
            ->assertUnauthorized();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_deleted_account_cannot_log_in(): void
    {
        $this->user->delete();

        $this->postJson(self::URL, $this->credentials())->assertUnauthorized();
    }

    public function test_missing_or_malformed_fields_return_validation_errors(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login', 'password'])
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);

        $this->postJson(self::URL, ['login' => ['90123456'], 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);
    }

    public function test_login_attempts_are_limited_per_account_and_do_not_block_other_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->credentials(['password' => 'mauvais']))->assertUnauthorized();
        }

        $this->postJson(self::URL, $this->credentials(['password' => 'mauvais']))
            ->assertStatus(429)
            ->assertExactJson(['message' => ApiMessages::TOO_MANY_REQUESTS])
            ->assertHeader('Retry-After');

        // The same account stays blocked, even with the right password and another phone format.
        $this->postJson(self::URL, $this->credentials(['login' => '+22890123456']))->assertStatus(429);

        // Another account behind the same IP address is not affected.
        $this->postJson(self::URL, $this->credentials(['login' => '99 99 99 99', 'password' => 'mauvais']))
            ->assertUnauthorized();
    }
}
