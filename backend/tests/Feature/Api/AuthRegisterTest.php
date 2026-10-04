<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const URL = '/api/v1/auth/register';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kofi Mensah',
            'phone' => '90 12 34 56',
            'email' => 'Kofi@Example.COM',
            'password' => 'mot-de-passe-1',
            'password_confirmation' => 'mot-de-passe-1',
            'device_name' => 'Pixel 7',
        ], $overrides);
    }

    public function test_valid_registration_creates_an_artisan_account_and_returns_a_token(): void
    {
        $response = $this->postJson(self::URL, $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'user' => ['uuid', 'name', 'email', 'phone', 'role', 'is_active', 'email_verified_at', 'created_at'],
                'token',
                'token_type',
            ]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'artisan')
            ->assertJsonPath('data.user.phone', '+22890123456')
            ->assertJsonPath('data.user.email', 'kofi@example.com')
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonPath('data.user.email_verified_at', null);

        $user = User::where('phone', '+22890123456')->firstOrFail();

        $this->assertSame(UserRole::Artisan, $user->role);
        $this->assertSame('kofi@example.com', $user->email);
        $this->assertTrue(Str::isUuid($response->json('data.user.uuid')));
        $this->assertSame($user->uuid, $response->json('data.user.uuid'));
        $this->assertNotSame('mot-de-passe-1', $user->password);
        $this->assertTrue(Hash::check('mot-de-passe-1', $user->password));
    }

    public function test_the_returned_token_works_immediately(): void
    {
        $token = $this->postJson(self::URL, $this->payload())->json('data.token');

        $this->withApiToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '+22890123456');
    }

    public function test_the_response_never_exposes_the_password_or_internal_data(): void
    {
        $content = $this->postJson(self::URL, $this->payload())->assertCreated()->getContent();

        $this->assertStringNotContainsString('mot-de-passe-1', $content);
        $this->assertStringNotContainsString('"password"', $content);
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString('$2y$', $content);
    }

    public function test_token_name_comes_from_the_device_name_and_defaults_to_mobile(): void
    {
        $this->postJson(self::URL, $this->payload())->assertCreated();
        $this->postJson(self::URL, $this->payload(['phone' => '91 23 45 67', 'email' => null, 'device_name' => null]))->assertCreated();

        $this->assertEqualsCanonicalizing(['Pixel 7', 'mobile'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_email_is_optional(): void
    {
        $this->postJson(self::URL, $this->payload(['email' => null]))
            ->assertCreated()
            ->assertJsonPath('data.user.email', null);

        $this->assertNull(User::firstOrFail()->email);
    }

    public function test_every_accepted_phone_format_is_normalised(): void
    {
        $cases = [
            '90 12 34 56' => '+22890123456',
            '+228 91 23 45 67' => '+22891234567',
            '00228 92345678' => '+22892345678',
        ];

        foreach ($cases as $input => $expected) {
            $this->postJson(self::URL, $this->payload(['phone' => $input, 'email' => null]))
                ->assertCreated()
                ->assertJsonPath('data.user.phone', $expected);
        }

        $this->assertSame(3, User::count());
    }

    public function test_an_email_already_used_is_rejected_whatever_its_case(): void
    {
        User::factory()->create(['email' => 'kofi@example.com']);

        $this->postJson(self::URL, $this->payload(['email' => 'KOFI@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);

        $this->assertSame(1, User::count());
    }

    public function test_a_phone_already_used_is_rejected_whatever_its_format(): void
    {
        User::factory()->create(['phone' => '+22890123456']);

        $this->postJson(self::URL, $this->payload(['phone' => '90 12 34 56']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertSame(1, User::count());
    }

    public function test_a_deleted_account_keeps_its_phone_number_reserved(): void
    {
        User::factory()->create(['phone' => '+22890123456'])->delete();

        $this->postJson(self::URL, $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_an_empty_payload_returns_the_standard_validation_error_shape(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name', 'phone', 'password']])
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);

        $this->assertSame(0, User::count());
    }

    public function test_errors_are_json_even_without_the_accept_header(): void
    {
        $this->post(self::URL, [])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        foreach (['123', '+33612345678', '9012345', 'abcdefgh'] as $phone) {
            $this->postJson(self::URL, $this->payload(['phone' => $phone]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone']);
        }

        $this->assertSame(0, User::count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload(['email' => 'pas-un-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_rules_are_enforced(): void
    {
        $this->postJson(self::URL, $this->payload(['password' => 'court', 'password_confirmation' => 'court']))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson(self::URL, $this->payload(['password_confirmation' => 'autre-chose-1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson(self::URL, $this->payload(['password_confirmation' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $long = str_repeat('a', 73);
        $this->postJson(self::URL, $this->payload(['password' => $long, 'password_confirmation' => $long]))
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->assertSame(0, User::count());
    }

    public function test_privileged_roles_cannot_be_requested(): void
    {
        foreach (['admin', 'moderator', 'field_agent'] as $role) {
            $this->postJson(self::URL, $this->payload(['role' => $role]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['role']);
        }

        $this->assertSame(0, User::count());
    }

    public function test_the_artisan_role_may_be_requested_and_sensitive_fields_are_ignored(): void
    {
        $this->postJson(self::URL, $this->payload([
            'role' => 'artisan',
            'is_active' => false,
            'email_verified_at' => '2020-01-01 00:00:00',
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'id' => 999999,
        ]))->assertCreated();

        $user = User::firstOrFail();

        $this->assertSame(UserRole::Artisan, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame(999999, $user->id);
        $this->assertNotSame('00000000-0000-4000-8000-000000000000', $user->uuid);
    }

    public function test_service_turns_a_unique_violation_into_a_validation_error(): void
    {
        User::factory()->create(['phone' => '+22890123456']);

        $this->expectException(ValidationException::class);

        app(AuthService::class)->register([
            'name' => 'Kofi Mensah',
            'phone' => '+22890123456',
            'password' => 'mot-de-passe-1',
        ]);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::URL, [])->assertUnprocessable();
        }

        $this->postJson(self::URL, [])
            ->assertStatus(429)
            ->assertExactJson(['message' => ApiMessages::TOO_MANY_REQUESTS])
            ->assertHeader('Retry-After');
    }
}
