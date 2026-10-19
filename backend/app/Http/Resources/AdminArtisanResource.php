<?php

namespace App\Http\Resources;

use App\Models\Artisan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full profile for the staff: everything the owner sees (status, reason), plus who owns, created and
 * validated it, and the hidden photos. People are identified by uuid, never by internal id.
 *
 * @mixin Artisan
 */
class AdminArtisanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Artisan $artisan */
        $artisan = $this->resource;

        $base = ArtisanResource::make($artisan)->resolve($request);

        return array_merge($base, [
            'photos' => $artisan->relationLoaded('photos') ? GalleryPhotoResource::collection($artisan->photos)->resolve() : [],
            'owner' => $this->person($artisan->owner),
            'created_by' => $this->person($artisan->creator),
            'validated_by' => $this->person($artisan->validator),
            'validated_at' => $artisan->validated_at?->toIso8601String(),
            'updated_at' => $artisan->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array{uuid: string, name: string, role: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['uuid' => $user->uuid, 'name' => $user->name, 'role' => $user->role->value];
    }
}
