<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\InvalidStatusTransition;
use App\Http\Requests\Api\V1\ListAdminArtisansRequest;
use App\Http\Requests\Api\V1\ModerationReasonRequest;
use App\Http\Resources\AdminArtisanListResource;
use App\Http\Resources\AdminArtisanResource;
use App\Models\Artisan;
use App\Services\AdminArtisanDirectory;
use App\Services\ModerationService;
use App\Support\ApiMessages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Staff endpoints (the route group already requires an active admin or moderator account).
 */
class AdminArtisanController
{
    public function __construct(
        private AdminArtisanDirectory $directory,
        private ModerationService $moderation,
    ) {}

    public function index(ListAdminArtisansRequest $request): AnonymousResourceCollection
    {
        $artisans = $this->directory->query($request->validated())
            ->with($this->directory->listRelations())
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return AdminArtisanListResource::collection($artisans);
    }

    public function show(Artisan $artisan): AdminArtisanResource
    {
        return AdminArtisanResource::make($this->directory->loadDetail($artisan));
    }

    public function validate(Request $request, Artisan $artisan): AdminArtisanResource|JsonResponse
    {
        Gate::authorize('moderate', $artisan);

        return $this->transition(fn () => $this->moderation->approve($artisan, $request->user()));
    }

    public function reject(ModerationReasonRequest $request, Artisan $artisan): AdminArtisanResource|JsonResponse
    {
        return $this->transition(fn () => $this->moderation->reject($artisan, $request->user(), $request->validated('reason')));
    }

    public function suspend(ModerationReasonRequest $request, Artisan $artisan): AdminArtisanResource|JsonResponse
    {
        return $this->transition(fn () => $this->moderation->suspend($artisan, $request->user(), $request->validated('reason')));
    }

    /**
     * @param  Closure(): Artisan  $change
     */
    private function transition(Closure $change): AdminArtisanResource|JsonResponse
    {
        try {
            $artisan = $change();
        } catch (InvalidStatusTransition) {
            return response()->json(['message' => ApiMessages::INVALID_TRANSITION], 409);
        }

        return AdminArtisanResource::make($this->directory->loadDetail($artisan));
    }
}
