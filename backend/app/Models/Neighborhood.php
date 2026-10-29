<?php

namespace App\Models;

use App\Enums\GeoVerification;
use App\Enums\NeighborhoodKind;
use Database\Factories\NeighborhoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "verification" is deliberately not mass assignable: only the geography loader sets it.
 */
#[Fillable(['city_id', 'commune_id', 'name', 'slug', 'is_active', 'kind', 'source_ref'])]
class Neighborhood extends Model
{
    /** @use HasFactory<NeighborhoodFactory> */
    use HasFactory;

    /**
     * In-memory defaults, identical to the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'kind' => 'non_precise',
        'verification' => 'a_verifier',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'kind' => NeighborhoodKind::class,
            'verification' => GeoVerification::class,
        ];
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return HasMany<NeighborhoodAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(NeighborhoodAlias::class);
    }

    /**
     * @return HasMany<Artisan, $this>
     */
    public function artisans(): HasMany
    {
        return $this->hasMany(Artisan::class);
    }
}
