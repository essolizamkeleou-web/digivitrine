<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ArtisanStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Concerns\ResolvesViewer;
use App\Http\Requests\Api\V1\ListArtisansRequest;
use App\Http\Requests\Api\V1\StoreArtisanRequest;
use App\Http\Requests\Api\V1\UpdateArtisanRequest;
use App\Http\Resources\ArtisanListResource;
use App\Http\Resources\ArtisanResource;
use App\Models\Artisan;
use App\Services\ArtisanDirectory;
use App\Services\ArtisanService;
use App\Support\ApiMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ArtisanController
{
    use ResolvesViewer;

    public function __construct(
        private ArtisanService $artisans,
        private ArtisanDirectory $directory,
    ) {}

    public function index(ListArtisansRequest $request): AnonymousResourceCollection
    {
        $artisans = $this->directory->visible($request->validated())
            ->with($this->directory->listRelations())
            ->orderBy('business_name')
            ->orderBy('id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return ArtisanListResource::collection($artisans);
    }

    public function show(Request $request, Artisan $artisan): ArtisanResource
    {
        Gate::forUser($this->viewer($request))->authorize('view', $artisan);

        return ArtisanResource::make($this->directory->loadDetail($artisan));
    }

    public function store(StoreArtisanRequest $request): JsonResponse
    {
        $user = $this->viewer($request);

        Gate::authorize('create', Artisan::class);

        if ($user->role === UserRole::Artisan && $this->artisans->ownsAProfile($user)) {
            return response()->json(['message' => ApiMessages::PROFILE_EXISTS], 409);
        }

        $artisan = $this->artisans->create($user, $request->validated());

        return ArtisanResource::make($this->directory->loadDetail($artisan))->response()->setStatusCode(201);
    }

    public function update(UpdateArtisanRequest $request, Artisan $artisan): ArtisanResource
    {
        $user = $this->viewer($request);

        Gate::authorize('update', $artisan);

        $artisan = $this->artisans->update($user, $artisan, $request->validated());

        return ArtisanResource::make($this->directory->loadDetail($artisan));
    }

    public function submit(Request $request, Artisan $artisan): ArtisanResource|JsonResponse
    {
        Gate::authorize('submit', $artisan);

        if (! in_array($artisan->status, [ArtisanStatus::Draft, ArtisanStatus::Rejected], true)) {
            return response()->json(['message' => ApiMessages::STATUS_CONFLICT], 409);
        }

        return ArtisanResource::make($this->directory->loadDetail($this->artisans->submit($artisan)));
    }
}
