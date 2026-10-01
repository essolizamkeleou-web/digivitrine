<?php

namespace Database\Factories;

use App\Enums\ArtisanStatus;
use App\Models\Artisan;
use App\Models\Neighborhood;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Artisan>
 */
class ArtisanFactory extends Factory
{
    /**
     * Define the model's default state: a draft profile created by a field agent,
     * with no owner account yet. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'created_by' => User::factory()->fieldAgent(),
            'neighborhood_id' => Neighborhood::factory(),
            'business_name' => 'Atelier '.Str::title(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'phone' => '+228'.fake()->unique()->numerify('9#######'),
            'whatsapp_phone' => null,
            'address' => fake()->streetAddress(),
            'latitude' => null,
            'longitude' => null,
            'status' => ArtisanStatus::Draft,
            'status_reason' => null,
            'validated_by' => null,
            'validated_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ArtisanStatus::Draft]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => ArtisanStatus::Pending]);
    }

    public function validated(): static
    {
        return $this->state(fn () => [
            'status' => ArtisanStatus::Validated,
            'validated_by' => User::factory()->moderator(),
            'validated_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => ArtisanStatus::Rejected,
            'status_reason' => 'Photos manquantes (donnee de test).',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => ArtisanStatus::Suspended,
            'status_reason' => 'Signalement en cours d\'examen (donnee de test).',
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function withWhatsapp(): static
    {
        return $this->state(fn (array $attributes) => ['whatsapp_phone' => $attributes['phone']]);
    }

    /**
     * Fictional coordinates in the Lome area unless given explicitly.
     */
    public function withLocation(?float $latitude = null, ?float $longitude = null): static
    {
        return $this->state(fn () => [
            'latitude' => $latitude ?? fake()->latitude(6.10, 6.25),
            'longitude' => $longitude ?? fake()->longitude(1.15, 1.30),
        ]);
    }
}
