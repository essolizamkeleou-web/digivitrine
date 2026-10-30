<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ListCommunesRequest;
use App\Http\Resources\CommuneResource;
use App\Http\Resources\NeighborhoodResource;
use App\Models\Commune;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public catalogue of the communes of Greater Lome and of their active neighborhoods.
 * Only active communes of active cities are visible.
 */
class CommuneController
{
    public function index(ListCommunesRequest $request): AnonymousResourceCollection
    {
        $communes = $this->scope($request)
            ->with('city')
            ->orderBy('communes.id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return CommuneResource::collection($communes);
    }

    public function show(ListCommunesRequest $request, string $commune): CommuneResource
    {
        return CommuneResource::make($this->find($request, $commune)->load('city'));
    }

    public function neighborhoods(ListCommunesRequest $request, string $commune): AnonymousResourceCollection
    {
        $neighborhoods = $this->find($request, $commune)
            ->neighborhoods()
            ->where('is_active', true)
            ->with('commune')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return NeighborhoodResource::collection($neighborhoods);
    }

    /**
     * @return Builder<Commune>
     */
    private function scope(ListCommunesRequest $request): Builder
    {
        return Commune::query()
            ->where('communes.is_active', true)
            ->whereHas('city', fn (Builder $city) => $city
                ->where('is_active', true)
                ->where('country_code', $request->country())
                ->when($request->validated('city'), fn (Builder $query, string $slug) => $query->where('slug', $slug)));
    }

    private function find(ListCommunesRequest $request, string $slug): Commune
    {
        return $this->scope($request)->where('communes.slug', $slug)->firstOrFail();
    }
}
