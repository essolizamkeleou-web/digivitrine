<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PhotoLimitReached;
use App\Http\Controllers\Api\V1\Concerns\ResolvesViewer;
use App\Http\Requests\Api\V1\StorePhotoRequest;
use App\Http\Requests\Api\V1\UpdatePhotoRequest;
use App\Http\Resources\GalleryPhotoResource;
use App\Models\Artisan;
use App\Models\Photo;
use App\Services\PhotoService;
use App\Support\ApiMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PhotoController
{
    use ResolvesViewer;

    public function __construct(private PhotoService $photos) {}

    /**
     * Active photos for everybody; the owner, the creating agent and the staff also see hidden ones.
     */
    public function index(Request $request, Artisan $artisan): AnonymousResourceCollection
    {
        $viewer = $this->viewer($request);
        $gate = Gate::forUser($viewer);

        $gate->authorize('view', $artisan);

        $privileged = $viewer !== null && $gate->allows('viewPrivate', $artisan);

        $photos = $artisan->photos()
            ->when(! $privileged, fn ($query) => $query->where('is_active', true))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return GalleryPhotoResource::collection($photos);
    }

    public function store(StorePhotoRequest $request, Artisan $artisan): JsonResponse
    {
        try {
            $photo = $this->photos->store($artisan, $request->file('photo'));
        } catch (PhotoLimitReached) {
            return response()->json(['message' => ApiMessages::PHOTO_LIMIT], 409);
        }

        return GalleryPhotoResource::make($photo)->response()->setStatusCode(201);
    }

    public function update(UpdatePhotoRequest $request, Artisan $artisan, Photo $photo): GalleryPhotoResource
    {
        return GalleryPhotoResource::make($this->photos->update($artisan, $photo, $request->validated()));
    }

    public function destroy(Request $request, Artisan $artisan, Photo $photo): Response
    {
        $this->viewer($request);

        Gate::authorize('update', $artisan);

        $this->photos->delete($artisan, $photo);

        return response()->noContent();
    }
}
