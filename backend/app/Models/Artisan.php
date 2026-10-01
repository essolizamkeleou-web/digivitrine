<?php

namespace App\Models;

use App\Enums\ArtisanStatus;
use Database\Factories\ArtisanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Ownership, status and validation fields are deliberately NOT mass assignable.
#[Fillable([
    'neighborhood_id',
    'business_name',
    'description',
    'phone',
    'whatsapp_phone',
    'address',
    'latitude',
    'longitude',
])]
class Artisan extends Model
{
    /** @use HasFactory<ArtisanFactory> */
    use HasFactory, SoftDeletes;

    /**
     * In-memory defaults, identical to the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArtisanStatus::class,
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * The owner account (null until the artisan claims the profile).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The account that created the profile (the artisan or a field agent).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The moderator who validated the profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    /**
     * Profiles visible to the public (rule RG-05).
     *
     * @param  Builder<Artisan>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', ArtisanStatus::Validated->value);
    }
}
