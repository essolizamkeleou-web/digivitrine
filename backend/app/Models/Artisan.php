<?php

namespace App\Models;

use App\Enums\ArtisanStatus;
use Database\Factories\ArtisanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     * Categories of the artisan. The optional subcategory and the primary flag live on the pivot.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'artisan_categories')
            ->withPivot(['subcategory_id', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Subcategories of the artisan (read-only: attach them through categories()).
     *
     * @return BelongsToMany<Subcategory, $this>
     */
    public function subcategories(): BelongsToMany
    {
        return $this->belongsToMany(Subcategory::class, 'artisan_categories', 'artisan_id', 'subcategory_id')
            ->withPivot(['category_id', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * @return HasOne<Photo, $this>
     */
    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<OpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Accounts that saved this profile as a favorite.
     *
     * @return BelongsToMany<User, $this>
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * @return HasMany<ProfileEvent, $this>
     */
    public function profileEvents(): HasMany
    {
        return $this->hasMany(ProfileEvent::class);
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
