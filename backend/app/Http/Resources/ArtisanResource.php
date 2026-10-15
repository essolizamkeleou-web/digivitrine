<?php

namespace App\Http\Resources;

use App\Models\Artisan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Full profile. Status and reason are private (owner, creator, staff). The internal id and the
 * ownership columns are never exposed. The viewer is read from the bearer token of the request.
 *
 * @mixin Artisan
 */
class ArtisanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');
        $viewer = ($user instanceof User && $user->is_active) ? $user : null;
        $private = $viewer !== null && Gate::forUser($viewer)->allows('viewPrivate', $this->resource);

        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'description' => $this->description,
            'phone' => $this->phone,
            'whatsapp_phone' => $this->whatsapp_phone,
            'whatsapp_url' => $this->whatsappUrl(),
            'call_url' => 'tel:'.$this->phone,
            'address' => $this->address,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'neighborhood' => $this->whenLoaded('neighborhood', fn ($neighborhood) => [
                'slug' => $neighborhood->slug,
                'name' => $neighborhood->name,
                'city' => $neighborhood->relationLoaded('city') && $neighborhood->city !== null
                    ? ['slug' => $neighborhood->city->slug, 'name' => $neighborhood->city->name, 'country_code' => $neighborhood->city->country_code]
                    : null,
            ]),
            'categories' => $this->whenLoaded('categories', fn ($categories) => $this->categoryList($categories)),
            'services' => $this->whenLoaded('services', fn ($services) => $services->map(fn ($service) => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'description' => $service->description,
            ])->values()->all()),
            'opening_hours' => $this->whenLoaded('openingHours', fn ($hours) => $hours->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'opens_at' => $hour->opens_at === null ? null : substr((string) $hour->opens_at, 0, 5),
                'closes_at' => $hour->closes_at === null ? null : substr((string) $hour->closes_at, 0, 5),
                'is_closed' => (bool) $hour->is_closed,
            ])->values()->all()),
            'photos' => $this->whenLoaded('photos', fn ($photos) => PhotoResource::collection($photos)->resolve()),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($private, fn () => [
                'status' => $this->status->value,
                'status_reason' => $this->status_reason,
            ]),
        ];
    }

    /**
     * WhatsApp link without the "+" (rule RG-02), with a prefilled message.
     */
    private function whatsappUrl(): ?string
    {
        if ($this->whatsapp_phone === null) {
            return null;
        }

        return 'https://wa.me/'.ltrim($this->whatsapp_phone, '+').'?text='.rawurlencode('Bonjour, je vous contacte depuis DigiVitrine.');
    }

    /**
     * @param  iterable<int, mixed>  $categories
     * @return list<array<string, mixed>>
     */
    private function categoryList(iterable $categories): array
    {
        $subcategories = $this->subcategories->keyBy('id');

        return collect($categories)->map(function ($category) use ($subcategories) {
            $subcategory = $subcategories->get($category->pivot->subcategory_id);

            return [
                'slug' => $category->slug,
                'name' => $category->name,
                'subcategory' => $subcategory === null ? null : ['slug' => $subcategory->slug, 'name' => $subcategory->name],
                'is_primary' => (bool) $category->pivot->is_primary,
            ];
        })->values()->all();
    }
}
