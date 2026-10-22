<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CreateStaffAccount as Command;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class CreateStaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'mot-de-passe-solide-1';

    /**
     * The command with its two hidden password prompts and the final confirmation.
     *
     * @param  array<string, mixed>  $options
     */
    private function createStaff(array $options, ?string $password = self::PASSWORD, ?string $confirmation = null, string $answer = 'yes'): PendingCommand
    {
        return $this->artisan('staff:create', $options)
            ->expectsQuestion(Command::PASSWORD_PROMPT, $password)
            ->expectsQuestion(Command::CONFIRM_PROMPT, $confirmation ?? $password)
            ->expectsConfirmation(Command::CONFIRM_CREATE, $answer);
    }

    public function test_the_command_is_registered_and_has_no_password_option(): void
    {
        $all = Artisan::all();

        $this->assertArrayHasKey('staff:create', $all);

        $definition = $all['staff:create']->getDefinition();

        foreach (array_keys($definition->getOptions()) as $option) {
            $this->assertStringNotContainsStringIgnoringCase('pass', $option);
        }

        foreach (array_keys($definition->getArguments()) as $argument) {
            $this->assertStringNotContainsStringIgnoringCase('pass', $argument);
        }

        $this->assertEqualsCanonicalizing(['role', 'name', 'phone', 'email'], array_diff(
            array_keys($definition->getOptions()),
            ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env']
        ));
    }

    public function test_it_creates_an_administrator_from_a_phone_number(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'])->assertExitCode(0);

        $user = User::where('phone', '+22890123456')->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame('Ama Admin', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Str::isUuid($user->uuid));
        $this->assertSame(1, User::count());
    }

    public function test_the_password_is_hashed_never_stored_in_clear(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'])->assertExitCode(0);

        $stored = (string) User::firstOrFail()->password;

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
    }

    public function test_it_creates_a_moderator_and_a_field_agent_with_a_normalised_email(): void
    {
        $expected = ['moderator' => UserRole::Moderator, 'field_agent' => UserRole::FieldAgent];

        foreach ($expected as $role => $enum) {
            $this->createStaff(['--role' => $role, '--name' => "Staff {$role}", '--email' => "  {$role}@Example.COM "])->assertExitCode(0);

            $user = User::where('email', "{$role}@example.com")->firstOrFail();

            $this->assertSame($enum, $user->role);
            $this->assertNull($user->phone);
            $this->assertTrue($user->is_active);
        }

        $this->assertSame(2, User::count());
    }

    public function test_missing_values_are_asked_interactively(): void
    {
        $this->artisan('staff:create')
            ->expectsChoice(Command::ROLE_PROMPT, 'moderator', Command::ROLES)
            ->expectsQuestion(Command::NAME_PROMPT, 'Kofi Modo')
            ->expectsQuestion(Command::PHONE_PROMPT, '92 11 22 33')
            ->expectsQuestion(Command::EMAIL_PROMPT, '')
            ->expectsQuestion(Command::PASSWORD_PROMPT, self::PASSWORD)
            ->expectsQuestion(Command::CONFIRM_PROMPT, self::PASSWORD)
            ->expectsConfirmation(Command::CONFIRM_CREATE, 'yes')
            ->assertExitCode(0);

        $user = User::where('phone', '+22892112233')->firstOrFail();

        $this->assertSame(UserRole::Moderator, $user->role);
        $this->assertSame('Kofi Modo', $user->name);
        $this->assertNull($user->email);
    }

    public function test_the_created_accounts_can_log_in_and_use_the_admin_api(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'])->assertExitCode(0);
        $this->createStaff(['--role' => 'field_agent', '--name' => 'Yao Agent', '--email' => 'agent@example.com'])->assertExitCode(0);

        $adminToken = $this->postJson('/api/v1/auth/login', ['login' => '90 12 34 56', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->json('data.token');

        $this->withToken($adminToken)->getJson('/api/v1/admin/artisans')->assertOk();

        $agentToken = $this->postJson('/api/v1/auth/login', ['login' => 'agent@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'field_agent')
            ->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->flushHeaders();
        $this->withToken($agentToken)->getJson('/api/v1/admin/artisans')->assertForbidden();
    }

    public function test_the_public_sign_up_still_cannot_create_privileged_accounts(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pirate Test',
            'phone' => '93 00 00 01',
            'password' => 'mot-de-passe-1',
            'password_confirmation' => 'mot-de-passe-1',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->assertSame(0, User::count());
    }

    public function test_the_artisan_role_and_unknown_roles_are_refused(): void
    {
        foreach (['artisan', 'superadmin', 'ADMIN'] as $role) {
            $this->artisan('staff:create', ['--role' => $role, '--name' => 'Test Role', '--phone' => '90 12 34 56'])->assertExitCode(1);
        }

        $this->assertSame(0, User::count());
    }

    public function test_duplicates_are_refused_before_the_password_is_asked(): void
    {
        User::factory()->create(['phone' => '+22890123456', 'email' => 'taken@example.com']);

        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Doublon Un', '--phone' => '90 12 34 56'])->assertExitCode(1);
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Doublon Deux', '--phone' => '+228 90123456'])->assertExitCode(1);
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Doublon Trois', '--email' => 'TAKEN@example.com'])->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_a_deleted_account_keeps_its_phone_reserved(): void
    {
        User::factory()->create(['phone' => '+22890123456'])->delete();

        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Doublon Un', '--phone' => '90 12 34 56'])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_invalid_contact_details_and_names_are_refused(): void
    {
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Test Un', '--phone' => '123'])->assertExitCode(1);
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Test Deux', '--phone' => '+33612345678'])->assertExitCode(1);
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Test Trois', '--email' => 'pas-un-email'])->assertExitCode(1);
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'A', '--phone' => '90 12 34 56'])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_a_phone_or_an_email_is_required(): void
    {
        $this->artisan('staff:create', ['--role' => 'admin', '--name' => 'Sans Contact'])
            ->expectsQuestion(Command::PHONE_PROMPT, '')
            ->expectsQuestion(Command::EMAIL_PROMPT, '')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_weak_empty_or_mismatched_passwords_are_refused(): void
    {
        $options = ['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'];

        $cases = [
            ['court', 'court'],
            ['', ''],
            [self::PASSWORD, 'autre-mot-de-passe-1'],
            [str_repeat('a', 73), str_repeat('a', 73)],
            ['onzecaracte', 'onzecaracte'],
        ];

        foreach ($cases as [$password, $confirmation]) {
            $this->artisan('staff:create', $options)
                ->expectsQuestion(Command::PASSWORD_PROMPT, $password)
                ->expectsQuestion(Command::CONFIRM_PROMPT, $confirmation)
                ->assertExitCode(1);
        }

        $this->assertSame(0, User::count());
    }

    public function test_a_twelve_character_password_is_accepted(): void
    {
        $this->createStaff(['--role' => 'moderator', '--name' => 'Ama Modo', '--phone' => '90 12 34 56'], 'douzecaract1')->assertExitCode(0);

        $this->assertSame(1, User::count());
    }

    public function test_declining_the_confirmation_creates_nothing(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'], self::PASSWORD, null, 'no')->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_the_command_refuses_to_run_without_interaction(): void
    {
        $this->artisan('staff:create', [
            '--role' => 'admin',
            '--name' => 'Ama Admin',
            '--phone' => '90 12 34 56',
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_a_password_cannot_be_given_as_an_option(): void
    {
        $this->assertFalse(Artisan::all()['staff:create']->getDefinition()->hasOption('password'));
    }

    public function test_the_log_contains_only_the_public_identifier_and_the_role(): void
    {
        Log::spy();

        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56', '--email' => 'ama@example.com'])->assertExitCode(0);

        $uuid = User::firstOrFail()->uuid;

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) use ($uuid): bool {
                $json = json_encode($context);

                return $message === 'staff.created'
                    && ($context['user'] ?? null) === $uuid
                    && ($context['role'] ?? null) === 'admin'
                    && ! str_contains($json, self::PASSWORD)
                    && ! str_contains($json, '90123456')
                    && ! str_contains($json, 'ama@example.com')
                    && ! str_contains($json, 'Ama Admin');
            })
            ->once();
    }

    public function test_an_account_with_phone_and_email_keeps_both(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '+228 91 23 45 67', '--email' => 'Ama@Example.com'])->assertExitCode(0);

        $user = User::firstOrFail();

        $this->assertSame('+22891234567', $user->phone);
        $this->assertSame('ama@example.com', $user->email);
    }

    public function test_two_accounts_can_be_created_one_after_the_other(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Premier Admin', '--phone' => '90 12 34 56'])->assertExitCode(0);
        $this->createStaff(['--role' => 'admin', '--name' => 'Second Admin', '--phone' => '90 12 34 57'])->assertExitCode(0);

        $this->assertSame(2, User::where('role', 'admin')->count());
        $this->assertSame(2, User::distinct('uuid')->count('uuid'));
    }

    public function test_the_role_is_not_mass_assignable_so_the_command_sets_it_explicitly(): void
    {
        $this->assertFalse((new User)->isFillable('role'));
        $this->assertFalse((new User)->isFillable('is_active'));

        $this->createStaff(['--role' => 'moderator', '--name' => 'Ama Modo', '--phone' => '90 12 34 56'])->assertExitCode(0);

        $this->assertSame(UserRole::Moderator, User::firstOrFail()->role);
    }

    public function test_the_command_creates_no_other_data(): void
    {
        $this->createStaff(['--role' => 'admin', '--name' => 'Ama Admin', '--phone' => '90 12 34 56'])->assertExitCode(0);

        $this->assertSame(0, \App\Models\Artisan::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
