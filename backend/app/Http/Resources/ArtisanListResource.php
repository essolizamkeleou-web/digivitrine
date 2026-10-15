<?php

namespace App\Http\Resources;

use App\Models\Artisan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Summary used in lists. Contact details are deliberately left out (they are in the profile).
 *
 * @mixin Artisan
 */
class ArtisanListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primary = $this->categories->first(fn ($category) => (bool) $category->pivot->is_primary) ?? $this->categories->first();
        $subcategoryId = $primary?->pivot->subcategory_id;
        $subcategory = $subcategoryId === null ? null : $this->subcategories->firstWhere('id', $subcategoryId);
        $photo = $this->primaryPhoto;

        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'description' => $this->description === null ? null : Str::limit($this->description, 160),
            'neighborhood' => $this->whenLoaded('neighborhood', fn ($neighborhood) => [
                'slug' => $neighborhood->slug,
                'name' => $neighborhood->name,
                'city' => $neighborhood->relationLoaded('city') && $neighborhood->city !== null
                    ? ['slug' => $neighborhood->city->slug, 'name' => $neighborhood->city->name, 'country_code' => $neighborhood->city->country_code]
                    : null,
            ]),
            'category' => $primary === null ? null : ['slug' => $primary->slug, 'name' => $primary->name],
            'subcategory' => $subcategory === null ? null : ['slug' => $subcategory->slug, 'name' => $subcategory->name],
            'primary_photo' => ($photo !== null && $photo->is_active) ? PhotoResource::make($photo)->resolve() : null,
        ];
    }
}
