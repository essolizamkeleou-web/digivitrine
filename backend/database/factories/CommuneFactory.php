<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Commune;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Commune>
 */
class CommuneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'Zzz Commune '.fake()->unique()->numberBetween(1, 1000000),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
            'prefecture' => 'test',
            'is_active' => true,
        ];
    }
}
