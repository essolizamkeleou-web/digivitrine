<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+228'.fake()->unique()->numerify('9#######'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Artisan,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function artisan(): static
    {
        return $this->state(fn () => ['role' => UserRole::Artisan]);
    }

    public function fieldAgent(): static
    {
        return $this->state(fn () => ['role' => UserRole::FieldAgent]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => ['role' => UserRole::Moderator]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }

    public function withoutPhone(): static
    {
        return $this->state(fn () => ['phone' => null]);
    }
}
