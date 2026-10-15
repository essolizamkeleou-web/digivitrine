<?php

namespace App\Services;

use App\Models\Artisan;
use App\Support\TextSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public search: the directory visibility rule and catalogue filters (ArtisanDirectory), plus
 * text matching, "open now" and ranking.
 */
class ArtisanSearch
{
    public function __construct(private ArtisanDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $params  optional keys: q, category, subcategory, city, neighborhood, country, available
     * @return Builder<Artisan>
     */
    public function query(array $params): Builder
    {
        $query = $this->directory->visible($params);
        $terms = TextSearch::terms($params['q'] ?? null);

        // Every word must be found somewhere (AND), in any of the searched fields (OR).
        foreach ($terms as $term) {
            $like = '%'.TextSearch::pattern($term).'%';

            $query->where(fn (Builder $group) => $this->matchAnywhere($group, $like));
        }

        if (! empty($params['available'])) {
            $this->openNow($query);
        }

        $this->rank($query, $terms);

        return $query;
    }

    /**
     * @param  Builder<Artisan>  $query
     */
    private function matchAnywhere(Builder $query, string $like): void
    {
        $matches = fn (string $column): string => TextSearch::sqlNormalized($column).' LIKE ?';

        $query->whereRaw($matches('artisans.business_name'), [$like])
            ->orWhereRaw($matches('artisans.description'), [$like])
            ->orWhereHas('categories', fn ($category) => $category->whereRaw($matches('categories.name'), [$like]))
            ->orWhereHas('subcategories', fn ($subcategory) => $subcategory->whereRaw($matches('subcategories.name'), [$like]))
            ->orWhereHas('services', fn ($service) => $service->where('services.is_active', true)->whereRaw($matches('services.name'), [$like]))
            ->orWhereHas('neighborhood', fn ($neighborhood) => $neighborhood->whereRaw($matches('neighborhoods.name'), [$like]))
            ->orWhereHas('neighborhood.city', fn ($city) => $city->whereRaw($matches('cities.name'), [$like]));
    }

    /**
     * Profiles with an opening slot that contains the current time (server time zone).
     *
     * @param  Builder<Artisan>  $query
     */
    private function openNow(Builder $query): void
    {
        $now = now();
        $time = $now->format('H:i:s');

        $query->whereHas('openingHours', fn ($hours) => $hours
            ->where('is_closed', false)
            ->where('day_of_week', $now->dayOfWeekIso)
            ->where('opens_at', '<=', $time)
            ->where('closes_at', '>', $time));
    }

    /**
     * Profiles whose business name contains the most searched words come first, then alphabetical order.
     *
     * @param  Builder<Artisan>  $query
     * @param  list<string>  $terms
     */
    private function rank(Builder $query, array $terms): void
    {
        if ($terms !== []) {
            $name = TextSearch::sqlNormalized('artisans.business_name');
            $cases = [];
            $bindings = [];

            foreach ($terms as $term) {
                $cases[] = "(CASE WHEN {$name} LIKE ? THEN 1 ELSE 0 END)";
                $bindings[] = '%'.TextSearch::pattern($term).'%';
            }

            $query->orderByRaw('('.implode(' + ', $cases).') DESC', $bindings);
        }

        $query->orderBy('artisans.business_name')->orderBy('artisans.id');
    }
}
