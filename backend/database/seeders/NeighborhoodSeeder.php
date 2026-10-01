<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Neighborhood;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Neighborhoods per city (ADR-10, section 2.7).
 *
 * TO BE COMPLETED: the reference list of the neighborhoods of Lome has NOT been validated yet.
 * Do not fill data() with approximate names: the list must come from the field team.
 *
 * Idempotent and non-destructive: a neighborhood is created only when its (city, slug) is missing,
 * and existing rows are never modified. Slugs are generated from the names.
 */
class NeighborhoodSeeder extends Seeder
{
    /**
     * Neighborhood names per city, keyed by "COUNTRY_CODE:city-slug".
     *
     * @return array<string, list<string>>
     */
    public function data(): array
    {
        return [
            'TG:lome' => [
                // To be provided and validated with the field team.
            ],
        ];
    }

    public function run(): void
    {
        $declared = 0;
        $created = 0;

        foreach ($this->data() as $cityKey => $names) {
            if ($names === []) {
                continue;
            }

            [$countryCode, $citySlug] = explode(':', $cityKey, 2);

            $city = City::where('country_code', $countryCode)->where('slug', $citySlug)->first()
                ?? throw new RuntimeException("Unknown city {$cityKey}: run CitySeeder first.");

            foreach ($names as $name) {
                $declared++;

                $neighborhood = Neighborhood::firstOrCreate(
                    ['city_id' => $city->id, 'slug' => Str::slug($name)],
                    ['name' => $name, 'is_active' => true],
                );

                if ($neighborhood->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        if ($declared === 0) {
            $this->command?->warn('NeighborhoodSeeder : AUCUN quartier declare. La liste des quartiers de Lome reste a fournir et a valider avec le terrain.');

            return;
        }

        $this->command?->info("NeighborhoodSeeder : {$declared} quartier(s) declare(s), {$created} cree(s).");
    }
}
