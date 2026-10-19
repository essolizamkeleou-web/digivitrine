<?php

namespace App\Services;

use App\Models\Artisan;
use App\Support\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Staff view of the profiles: every status (soft-deleted profiles excluded), catalogue filters,
 * name search. Unlike the public directory it applies no visibility rule.
 */
class AdminArtisanDirectory
{
    /**
     * @param  array<string, mixed>  $params  optional keys: status, q, category, subcategory, city, neighborhood, country
     * @return Builder<Artisan>
     */
    public function query(array $params): Builder
    {
        $country = $params['country'] ?? (string) config('digivitrine.default_country');
        $status = $params['status'] ?? null;

        $query = Artisan::query()
            ->when($status, fn (Builder $q, string $value) => $q->where('artisans.status', $value))
            ->when($params['category'] ?? null, fn (Builder $q, string $slug) => $q
                ->whereHas('categories', fn (Builder $category) => $category->where('categories.slug', $slug)))
            ->when($params['subcategory'] ?? null, fn (Builder $q, string $slug) => $q
                ->whereHas('subcategories', fn (Builder $subcategory) => $subcategory->where('subcategories.slug', $slug)))
            ->when($params['neighborhood'] ?? null, fn (Builder $q, string $slug) => $q
                ->whereHas('neighborhood', fn (Builder $neighborhood) => $neighborhood->where('slug', $slug)))
            ->when($params['city'] ?? null, fn (Builder $q, string $slug) => $q
                ->whereHas('neighborhood.city', fn (Builder $city) => $city->where('slug', $slug)->where('country_code', $country)));

        // Words only contain [a-z0-9] (see TextSearch): safe to bind inside a LIKE.
        foreach (TextSearch::terms($params['q'] ?? null) as $term) {
            $query->whereRaw(TextSearch::sqlNormalized('artisans.business_name').' LIKE ?', ['%'.$term.'%']);
        }

        // Moderation queue: the oldest pending profiles first; otherwise the newest first.
        return $query
            ->orderBy('artisans.created_at', $status === 'pending' ? 'asc' : 'desc')
            ->orderBy('artisans.id');
    }

    /**
     * @return list<string>
     */
    public function listRelations(): array
    {
        return ['neighborhood.city', 'categories', 'subcategories'];
    }

    public function loadDetail(Artisan $artisan): Artisan
    {
        return $artisan->load([
            'neighborhood.city',
            'categories',
            'subcategories',
            'services' => fn (Relation $query) => $query->where('is_active', true)->orderBy('position')->orderBy('id'),
            'photos' => fn (Relation $query) => $query->orderBy('position')->orderBy('id'),
            'openingHours' => fn (Relation $query) => $query->orderBy('day_of_week')->orderBy('opens_at'),
            'owner',
            'creator',
            'validator',
        ]);
    }
}
