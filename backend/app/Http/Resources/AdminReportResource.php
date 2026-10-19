<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class AdminReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $artisan = $this->relationLoaded('artisan') ? $this->artisan : null;
        $reporter = $this->relationLoaded('reporter') ? $this->reporter : null;
        $resolver = $this->relationLoaded('resolver') ? $this->resolver : null;

        return [
            'uuid' => $this->uuid,
            'reason' => $this->reason->value,
            'description' => $this->description,
            'status' => $this->status->value,
            'artisan' => $artisan === null ? null : [
                'uuid' => $artisan->uuid,
                'business_name' => $artisan->business_name,
                'status' => $artisan->status->value,
            ],
            'reporter' => $reporter === null ? null : ['uuid' => $reporter->uuid, 'name' => $reporter->name],
            'resolved_by' => $resolver === null ? null : ['uuid' => $resolver->uuid, 'name' => $resolver->name],
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
