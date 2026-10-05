<?php

namespace App\Http\Resources;

use App\Models\Neighborhood;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Neighborhood
 */
class NeighborhoodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->whenLoaded('city', fn ($city) => CityResource::make($city)->resolve()),
        ];
    }
}
