<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Artisan;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state: an open report filed by a user. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'user_id' => User::factory(),
            'reason' => ReportReason::FalseInformation,
            'description' => fake()->sentence(),
            'status' => ReportStatus::Open,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function anonymous(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function forReason(ReportReason $reason): static
    {
        return $this->state(fn () => ['reason' => $reason]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Resolved,
            'resolved_by' => User::factory()->moderator(),
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Dismissed,
            'resolved_by' => User::factory()->moderator(),
            'resolved_at' => now(),
        ]);
    }
}
