<?php

namespace Database\Factories;

use App\Models\Artisan;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Favorite>
 */
class FavoriteFactory extends Factory
{
    /**
     * Define the model's default state. All data is fictional.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'artisan_id' => Artisan::factory(),
        ];
    }
}
