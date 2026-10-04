<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of the authenticated account.
 * An explicit allow-list: the internal id, the password and the remember token are never exposed.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Only when the relation is loaded: null means "no profile yet".
            'artisan' => $this->whenLoaded('artisan', fn ($artisan) => [
                'uuid' => $artisan->uuid,
                'business_name' => $artisan->business_name,
                'status' => $artisan->status->value,
            ]),
        ];
    }
}
