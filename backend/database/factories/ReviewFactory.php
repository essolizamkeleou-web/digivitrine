<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Artisan;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->sentence(),
            'status' => ReviewStatus::Pending,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => ReviewStatus::Published]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => ReviewStatus::Hidden]);
    }

    public function withoutComment(): static
    {
        return $this->state(fn () => ['comment' => null]);
    }
}
