<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\SearchArtisansRequest;
use App\Http\Resources\ArtisanListResource;
use App\Services\ArtisanDirectory;
use App\Services\ArtisanSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController
{
    public function __construct(
        private ArtisanSearch $search,
        private ArtisanDirectory $directory,
    ) {}

    public function artisans(SearchArtisansRequest $request): AnonymousResourceCollection
    {
        $params = $request->validated();
        $params['available'] = $request->boolean('available');

        $artisans = $this->search->query($params)
            ->with($this->directory->listRelations())
            ->paginate($params['per_page'] ?? 20)
            ->withQueryString();

        return ArtisanListResource::collection($artisans);
    }
}
