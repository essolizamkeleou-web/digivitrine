<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal valid row for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawUser(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Utilisateur Test',
            'phone' => '+22890000001',
            'password' => 'not-a-real-hash',
        ], $overrides);
    }

    public function test_user_can_be_created_with_each_valid_role(): void
    {
        $expected = [
            'artisan' => UserRole::Artisan,
            'fieldAgent' => UserRole::FieldAgent,
            'moderator' => UserRole::Moderator,
            'admin' => UserRole::Admin,
        ];

        foreach ($expected as $state => $role) {
            $user = User::factory()->{$state}()->create();

            $this->assertSame($role, $user->fresh()->role);
        }
    }

    public function test_model_defaults_to_artisan_role_and_active_account(): void
    {
        $user = User::create([
            'name' => 'Kofi Test',
            'phone' => '+22890000002',
            'password' => 'secret-password',
        ])->fresh();

        $this->assertSame(UserRole::Artisan, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_database_applies_default_role_active_flag_and_uuid(): void
    {
        DB::table('users')->insert($this->rawUser());

        $user = User::firstOrFail();

        $this->assertSame(UserRole::Artisan, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Str::isUuid($user->uuid));
    }

    public function test_email_is_optional(): void
    {
        User::factory()->withoutEmail()->create();
        User::factory()->withoutEmail()->create();

        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_email_must_be_unique_when_present(): void
    {
        User::factory()->create(['email' => 'kofi@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'kofi@example.com']);
    }

    public function test_email_is_trimmed_and_lowercased_by_the_model(): void
    {
        $user = User::create([
            'name' => 'Ama Test',
            'email' => '  Ama.Test@Example.COM ',
            'password' => 'secret-password',
        ]);

        $this->assertSame('ama.test@example.com', $user->fresh()->email);
    }

    public function test_phone_accepts_international_numbers(): void
    {
        $togo = User::factory()->create(['phone' => '+22890123456']);
        $france = User::factory()->create(['phone' => '+33612345678']);

        $this->assertSame('+22890123456', $togo->fresh()->phone);
        $this->assertSame('+33612345678', $france->fresh()->phone);
    }

    public function test_phone_must_be_unique(): void
    {
        User::factory()->create(['phone' => '+22890123456']);

        $this->expectException(QueryException::class);

        User::factory()->create(['phone' => '+22890123456']);
    }

    public function test_password_is_hashed_when_created_by_the_application(): void
    {
        $user = User::create([
            'name' => 'Yao Test',
            'phone' => '+22890000003',
            'password' => 'mot-de-passe-secret',
        ]);

        $stored = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertNotSame('mot-de-passe-secret', $stored);
        $this->assertTrue(Hash::check('mot-de-passe-secret', $stored));
    }

    public function test_role_and_active_flag_are_not_mass_assignable(): void
    {
        $user = new User;

        $this->assertFalse($user->isFillable('role'));
        $this->assertFalse($user->isFillable('is_active'));
        $this->assertTrue($user->isFillable('phone'));
    }

    public function test_sensitive_attributes_are_hidden_when_serialised(): void
    {
        $array = User::factory()->create()->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    public function test_users_are_soft_deleted(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted($user);
    }

    public function test_database_rejects_an_unknown_role(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->rawUser(['role' => 'superhero']));
    }

    public function test_database_rejects_a_malformed_phone_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->rawUser(['phone' => '0612345678']));
    }

    public function test_database_rejects_a_user_without_email_and_phone(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->rawUser(['phone' => null, 'email' => null]));
    }

    public function test_database_rejects_an_uppercase_email(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->rawUser(['email' => 'Kofi@Example.com']));
    }
}
