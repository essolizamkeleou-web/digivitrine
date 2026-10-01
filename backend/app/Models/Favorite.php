<?php

namespace App\Models;

use Database\Factories\FavoriteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nothing is mass assignable: favorites are created through User::favoriteArtisans().
class Favorite extends Model
{
    /** @use HasFactory<FavoriteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Artisan, $this>
     */
    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class);
    }
}
