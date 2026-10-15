<?php

namespace App\Services;

use App\Models\Artisan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Read side of the public directory: visibility rule (validated profiles only) and catalogue filters.
 * The search endpoint builds on visible().
 */
class ArtisanDirectory
{
    /**
     * @param  array<string, mixed>  $filters  optional keys: category, subcategory, city, neighborhood, country
     * @return Builder<Artisan>
     */
    public function visible(array $filters = []): Builder
    {
        $country = $filters['country'] ?? (string) config('digivitrine.default_country');

        return Artisan::query()
            ->publiclyVisible()
            ->when($filters['category'] ?? null, fn (Builder $query, string $slug) => $query
                ->whereHas('categories', fn (Builder $category) => $category->where('categories.slug', $slug)))
            ->when($filters['subcategory'] ?? null, fn (Builder $query, string $slug) => $query
                ->whereHas('subcategories', fn (Builder $subcategory) => $subcategory->where('subcategories.slug', $slug)))
            ->when($filters['neighborhood'] ?? null, fn (Builder $query, string $slug) => $query
                ->whereHas('neighborhood', fn (Builder $neighborhood) => $neighborhood->where('slug', $slug)))
            ->when($filters['city'] ?? null, fn (Builder $query, string $slug) => $query
                ->whereHas('neighborhood.city', fn (Builder $city) => $city->where('slug', $slug)->where('country_code', $country)));
    }

    /**
     * Relations used by the list resource.
     *
     * @return list<string>
     */
    public function listRelations(): array
    {
        return ['neighborhood.city', 'categories', 'subcategories', 'primaryPhoto'];
    }

    public function loadDetail(Artisan $artisan): Artisan
    {
        return $artisan->load([
            'neighborhood.city',
            'categories',
            'subcategories',
            'services' => fn (Relation $query) => $query->where('is_active', true)->orderBy('position')->orderBy('id'),
            'photos' => fn (Relation $query) => $query->where('is_active', true)->orderBy('position')->orderBy('id'),
            'openingHours' => fn (Relation $query) => $query->orderBy('day_of_week')->orderBy('opens_at'),
        ]);
    }
}
