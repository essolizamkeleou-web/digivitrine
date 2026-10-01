<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Reference data only. Fictional development data (users, artisans) is not seeded here:
     * the default demo user was removed because its fixed e-mail would break a second run.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
