<?php

namespace App\Http\Resources;

use App\Models\Artisan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row of the staff list: status, reason and contact number are visible, internal ids are not.
 *
 * @mixin Artisan
 */
class AdminArtisanListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primary = $this->categories->first(fn ($category) => (bool) $category->pivot->is_primary) ?? $this->categories->first();

        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'neighborhood' => $this->whenLoaded('neighborhood', fn ($neighborhood) => [
                'slug' => $neighborhood->slug,
                'name' => $neighborhood->name,
                'city' => $neighborhood->relationLoaded('city') && $neighborhood->city !== null
                    ? ['slug' => $neighborhood->city->slug, 'name' => $neighborhood->city->name, 'country_code' => $neighborhood->city->country_code]
                    : null,
            ]),
            'category' => $primary === null ? null : ['slug' => $primary->slug, 'name' => $primary->name],
            'has_owner' => $this->user_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
        ];
    }
}
