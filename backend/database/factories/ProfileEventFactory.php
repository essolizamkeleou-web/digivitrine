<?php

namespace Database\Factories;

use App\Enums\ProfileEventType;
use App\Models\Artisan;
use App\Models\ProfileEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfileEvent>
 */
class ProfileEventFactory extends Factory
{
    /**
     * Define the model's default state: an anonymous profile view. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'event_type' => ProfileEventType::ProfileView,
            'user_id' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function ofType(ProfileEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn () => ['metadata' => $metadata]);
    }

    public function at(CarbonInterface $date): static
    {
        return $this->state(fn () => ['created_at' => $date]);
    }
}
