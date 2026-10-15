<?php

namespace App\Http\Resources;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public photo: the storage disk and path stay internal, only a URL is exposed.
 *
 * @mixin Photo
 */
class PhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'url' => Storage::disk($this->disk)->url($this->path),
            'width' => $this->width,
            'height' => $this->height,
            'is_primary' => (bool) $this->is_primary,
            'position' => $this->position,
        ];
    }
}
