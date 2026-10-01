<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ownership, status and resolution fields are deliberately NOT mass assignable.
#[Fillable(['reason', 'description'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * In-memory defaults, identical to the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'datetime',
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
     * The account that filed the report (null when anonymous or deleted).
     *
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The moderator who closed the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ReportStatus::Open->value);
    }
}
