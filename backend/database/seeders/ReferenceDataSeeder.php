<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data only (safe to run in production):
 * php artisan db:seed --class=ReferenceDataSeeder
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CitySeeder::class,
            NeighborhoodSeeder::class,
            CategorySeeder::class,
            SubcategorySeeder::class,
        ]);
    }
}
