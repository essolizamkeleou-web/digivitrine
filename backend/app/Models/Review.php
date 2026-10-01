<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ownership, moderation status and uuid are deliberately NOT mass assignable.
#[Fillable(['rating', 'comment'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * In-memory defaults, identical to the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
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
     * The author (null once the account has been permanently deleted).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reviews visible to the public.
     *
     * @param  Builder<Review>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ReviewStatus::Published->value);
    }
}
