<?php

namespace App\Http\Resources;

use App\Models\Commune;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a commune: slugs and names only, never internal ids.
 *
 * @mixin Commune
 */
class CommuneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'prefecture' => $this->prefecture,
            'city' => $this->whenLoaded('city', fn () => [
                'slug' => $this->city->slug,
                'name' => $this->city->name,
                'country_code' => $this->city->country_code,
            ]),
        ];
    }
}
