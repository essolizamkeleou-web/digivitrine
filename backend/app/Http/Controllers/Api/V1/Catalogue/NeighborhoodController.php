<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Http\Controllers\Api\V1\Catalogue\Concerns\CachesReferenceData;
use App\Http\Requests\Api\V1\CatalogueListRequest;
use App\Http\Resources\NeighborhoodResource;
use App\Models\Neighborhood;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class NeighborhoodController
{
    use CachesReferenceData;

    /**
     * Active neighborhoods of active cities, optionally filtered by city slug and country.
     */
    public function index(CatalogueListRequest $request): JsonResponse
    {
        $neighborhoods = Neighborhood::query()
            ->where('is_active', true)
            ->whereHas('city', function (Builder $query) use ($request) {
                $query->where('is_active', true)
                    ->when($request->validated('country'), fn ($q, $country) => $q->where('country_code', $country))
                    ->when($request->validated('city'), fn ($q, $slug) => $q->where('slug', $slug));
            })
            ->with('city')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page') ?? 100)
            ->withQueryString();

        return $this->cacheable(NeighborhoodResource::collection($neighborhoods));
    }
}
