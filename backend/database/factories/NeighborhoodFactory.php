<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Neighborhood;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Neighborhood>
 */
class NeighborhoodFactory extends Factory
{
    /**
     * Define the model's default state. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => Str::title(fake()->unique()->words(2, true)),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
            'is_active' => true,
        ];
    }
}
