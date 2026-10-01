<?php

namespace App\Models;

use App\Enums\ProfileEventType;
use Database\Factories\ProfileEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ownership is deliberately NOT mass assignable. metadata must never contain personal data.
#[Fillable(['event_type', 'metadata'])]
class ProfileEvent extends Model
{
    /** @use HasFactory<ProfileEventFactory> */
    use HasFactory;

    // Append-only table: there is no updated_at column.
    const UPDATED_AT = null;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event_type' => ProfileEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Artisan, $this>
     */
    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
