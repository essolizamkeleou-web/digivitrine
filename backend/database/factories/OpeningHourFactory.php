<?php

namespace Database\Factories;

use App\Models\Artisan;
use App\Models\OpeningHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningHour>
 */
class OpeningHourFactory extends Factory
{
    /**
     * Define the model's default state: Monday 08:00-17:00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'day_of_week' => 1,
            'opens_at' => '08:00',
            'closes_at' => '17:00',
            'is_closed' => false,
        ];
    }

    public function forDay(int $dayOfWeek): static
    {
        return $this->state(fn () => ['day_of_week' => $dayOfWeek]);
    }

    public function between(string $opensAt, string $closesAt): static
    {
        return $this->state(fn () => ['opens_at' => $opensAt, 'closes_at' => $closesAt]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }
}
