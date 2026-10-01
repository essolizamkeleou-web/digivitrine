<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Cities of the pilot (ADR-10, section 2.7). Cities are data, never hard-coded in the application.
 *
 * Idempotent and non-destructive: a city is created only when its (country_code, slug) is missing,
 * and existing rows are never modified (an administrator may have edited them).
 *
 * Accents are written as \u{..} escapes so that this file stays pure ASCII.
 */
class CitySeeder extends Seeder
{
    /**
     * @return list<array{country_code: string, slug: string, name: string}>
     */
    public function data(): array
    {
        return [
            ['country_code' => 'TG', 'slug' => 'lome', 'name' => "Lom\u{E9}"],
        ];
    }

    public function run(): void
    {
        foreach ($this->data() as $city) {
            City::firstOrCreate(
                ['country_code' => $city['country_code'], 'slug' => $city['slug']],
                ['name' => $city['name'], 'is_active' => true],
            );
        }

        $this->command?->info('CitySeeder : '.City::count().' ville(s) en base.');
    }
}
