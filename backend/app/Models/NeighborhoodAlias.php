<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spelling variant of a neighborhood. alias_key is the comparison key: lowercase, no accents,
 * no spaces, hyphens, apostrophes or dots.
 */
#[Fillable(['neighborhood_id', 'alias', 'alias_key'])]
class NeighborhoodAlias extends Model
{
    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }
}
