<?php

namespace Database\Factories;

use App\Models\Artisan;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * Define the model's default state. All data is fictional: no real file exists at "path".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'disk' => 'public',
            'path' => 'artisans/test/'.Str::uuid().'.webp',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/webp',
            'size_bytes' => fake()->numberBetween(20_000, 500_000),
            'width' => 1280,
            'height' => 960,
            'position' => 0,
            'is_primary' => false,
            'is_active' => true,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
