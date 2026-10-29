<?php

namespace App\Services;

use App\Enums\GeoVerification;
use App\Enums\NeighborhoodKind;
use App\Models\City;
use App\Models\Commune;
use App\Models\Neighborhood;
use App\Models\NeighborhoodAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the geographic reference files (database/data/geography) into the database.
 *
 * The files are validated with the same functions as check.php. Nothing is written when there is any
 * error, in particular a slug collision with a row already in the database. Existing rows are never
 * modified. Unverified neighborhoods are created inactive: only verified ones are active.
 */
class GeographyLoader
{
    public function __construct()
    {
        require_once base_path('database/data/geography/check.php');
    }

    /**
     * Simulation: reads and checks everything, writes nothing.
     *
     * @return array{errors: list<string>, warnings: list<string>, plan: ?array<string, mixed>}
     */
    public function plan(?string $directory = null): array
    {
        $directory = rtrim($directory ?? database_path('data/geography'), '/\\');

        try {
            $sources = \geo_load($directory.'/sources.json');
            $communes = \geo_load($directory.'/togo/lome/communes.json');
            $files = [];

            foreach (glob($directory.'/togo/lome/neighborhoods/*.json') ?: [] as $path) {
                $files[basename($path, '.json')] = \geo_load($path);
            }
        } catch (RuntimeException $exception) {
            return ['errors' => [$exception->getMessage()], 'warnings' => [], 'plan' => null];
        }

        $result = \geo_validate($sources, $communes, $files);
        $errors = $result['errors'];
        $warnings = $result['warnings'];

        $cityData = $communes['city'] ?? [];
        $city = City::query()
            ->where('country_code', (string) ($cityData['country_code'] ?? ''))
            ->where('slug', (string) ($cityData['slug'] ?? ''))
            ->first();

        if ($city === null) {
            $errors[] = 'ville inconnue en base ('.($cityData['country_code'] ?? '?').' / '.($cityData['slug'] ?? '?').') : lancer CitySeeder';
        }

        if ($errors !== [] || $city === null) {
            return ['errors' => $errors, 'warnings' => $warnings, 'plan' => null];
        }

        $existingCommunes = Commune::query()->where('city_id', $city->id)->get()->keyBy('slug');
        $communePlan = [];

        foreach ($communes['communes'] as $commune) {
            $existing = $existingCommunes->get($commune['slug']);

            if ($existing !== null && $existing->name !== $commune['name']) {
                $warnings[] = "commune {$commune['slug']} : le nom en base ('{$existing->name}') differe du fichier ('{$commune['name']}') ; la base n'est pas modifiee";
            }

            $communePlan[] = ['slug' => $commune['slug'], 'name' => $commune['name'], 'prefecture' => $commune['prefecture'], 'exists' => $existing !== null];
        }

        $entries = [];

        foreach ($files as $file) {
            foreach ($file['neighborhoods'] as $entry) {
                $entries[] = $entry;
            }
        }

        $existingNeighborhoods = Neighborhood::query()
            ->where('city_id', $city->id)
            ->whereIn('slug', array_column($entries, 'slug'))
            ->get()
            ->keyBy('slug');
        $communeById = $existingCommunes->keyBy('id');
        $neighborhoodPlan = [];

        foreach ($entries as $entry) {
            $row = $existingNeighborhoods->get($entry['slug']);
            $state = 'create';

            if ($row !== null) {
                $rowCommune = $row->commune_id === null ? null : $communeById->get($row->commune_id)?->slug;

                if ($rowCommune !== $entry['commune']) {
                    $errors[] = "collision : le slug '{$entry['slug']}' existe deja en base pour '{$row->name}' (".($rowCommune ?? 'sans commune')."), l'entree du fichier est '{$entry['name']}' ({$entry['commune']}). Rien n'est ecrit : a trancher par un humain.";

                    continue;
                }

                if ($row->name !== $entry['name']) {
                    $warnings[] = "quartier {$entry['slug']} : le nom en base ('{$row->name}') differe du fichier ('{$entry['name']}') ; la base n'est pas modifiee";
                }

                $state = 'present';
            }

            $nameKey = \geo_key($entry['name']);
            $aliases = [];

            foreach ($entry['aliases'] ?? [] as $alias) {
                $key = \geo_key($alias);

                if ($key !== '' && $key !== $nameKey && ! isset($aliases[$key])) {
                    $aliases[$key] = ['alias' => $alias, 'key' => $key];
                }
            }

            $neighborhoodPlan[] = [
                'commune' => $entry['commune'],
                'name' => $entry['name'],
                'slug' => $entry['slug'],
                'kind' => $entry['kind'],
                'verification' => $entry['verification'],
                'source_ref' => Str::limit(implode(', ', $entry['sources'] ?? []), 255, ''),
                'aliases' => array_values($aliases),
                'state' => $state,
                'existing_id' => $row?->id,
            ];
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => $warnings, 'plan' => null];
        }

        return ['errors' => [], 'warnings' => $warnings, 'plan' => ['city_id' => $city->id, 'communes' => $communePlan, 'neighborhoods' => $neighborhoodPlan]];
    }

    /**
     * Writes the plan in one transaction. Returns the plan result plus the created counts (null on error).
     *
     * @return array{errors: list<string>, warnings: list<string>, plan: ?array<string, mixed>, created: ?array<string, int>}
     */
    public function apply(?string $directory = null): array
    {
        $result = $this->plan($directory);

        if ($result['errors'] !== [] || $result['plan'] === null) {
            return $result + ['created' => null];
        }

        $plan = $result['plan'];
        $created = ['communes' => 0, 'neighborhoods' => 0, 'aliases' => 0];

        DB::transaction(function () use ($plan, &$created): void {
            foreach ($plan['communes'] as $commune) {
                if ($commune['exists']) {
                    continue;
                }

                Commune::query()->create([
                    'city_id' => $plan['city_id'],
                    'name' => $commune['name'],
                    'slug' => $commune['slug'],
                    'prefecture' => $commune['prefecture'],
                ]);
                $created['communes']++;
            }

            $communeIds = Commune::query()->where('city_id', $plan['city_id'])->pluck('id', 'slug');

            foreach ($plan['neighborhoods'] as $item) {
                $id = $item['existing_id'];

                if ($item['state'] === 'create') {
                    $neighborhood = new Neighborhood;
                    $neighborhood->fill([
                        'city_id' => $plan['city_id'],
                        'commune_id' => $communeIds[$item['commune']],
                        'name' => $item['name'],
                        'slug' => $item['slug'],
                        'kind' => NeighborhoodKind::from($item['kind']),
                        'source_ref' => $item['source_ref'] === '' ? null : $item['source_ref'],
                        'is_active' => $item['verification'] === GeoVerification::Verified->value,
                    ]);
                    $neighborhood->forceFill(['verification' => GeoVerification::from($item['verification'])]);
                    $neighborhood->save();
                    $created['neighborhoods']++;
                    $id = $neighborhood->id;
                }

                foreach ($item['aliases'] as $alias) {
                    $row = NeighborhoodAlias::query()->firstOrCreate(
                        ['neighborhood_id' => $id, 'alias_key' => $alias['key']],
                        ['alias' => $alias['alias']],
                    );

                    if ($row->wasRecentlyCreated) {
                        $created['aliases']++;
                    }
                }
            }
        });

        return $result + ['created' => $created];
    }
}
