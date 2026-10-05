<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Http\Controllers\Api\V1\Catalogue\Concerns\CachesReferenceData;
use App\Http\Requests\Api\V1\CatalogueListRequest;
use App\Http\Resources\CityResource;
use App\Http\Resources\NeighborhoodResource;
use App\Models\City;
use Illuminate\Http\JsonResponse;

class CityController
{
    use CachesReferenceData;

    public function index(CatalogueListRequest $request): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->when($request->validated('country'), fn ($query, $country) => $query->where('country_code', $country))
            ->orderBy('country_code')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->cacheable(CityResource::collection($cities));
    }

    public function show(CatalogueListRequest $request, string $city): JsonResponse
    {
        return $this->cacheable(CityResource::make($this->find($city, $request->country())));
    }

    public function neighborhoods(CatalogueListRequest $request, string $city): JsonResponse
    {
        $neighborhoods = $this->find($city, $request->country())
            ->neighborhoods()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page') ?? 100)
            ->withQueryString();

        return $this->cacheable(NeighborhoodResource::collection($neighborhoods));
    }

    private function find(string $slug, string $country): City
    {
        return City::query()
            ->where('is_active', true)
            ->where('country_code', $country)
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
