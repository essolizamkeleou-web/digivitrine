<?php

namespace App\Services;

use App\Enums\ArtisanStatus;
use App\Enums\ReportStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Artisan;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Staff decisions on profiles and reports. The acting user always comes from the authenticated request,
 * never from the payload. Each row is locked while its status is checked, so two moderators acting
 * at the same time cannot both succeed. Every decision is written to the application log.
 */
class ModerationService
{
    /**
     * Pending or suspended profile -> validated (records who validated and when).
     */
    public function approve(Artisan $artisan, User $actor): Artisan
    {
        return $this->changeStatus($artisan, $actor, ArtisanStatus::Validated, null);
    }

    /**
     * Pending profile -> rejected, with the reason shown to the owner.
     */
    public function reject(Artisan $artisan, User $actor, string $reason): Artisan
    {
        return $this->changeStatus($artisan, $actor, ArtisanStatus::Rejected, $reason);
    }

    /**
     * Validated profile -> suspended, with a reason.
     */
    public function suspend(Artisan $artisan, User $actor, string $reason): Artisan
    {
        return $this->changeStatus($artisan, $actor, ArtisanStatus::Suspended, $reason);
    }

    /**
     * Open report -> resolved or dismissed.
     *
     * @throws InvalidStatusTransition
     */
    public function closeReport(Report $report, User $actor, ReportStatus $to): Report
    {
        DB::transaction(function () use ($report, $actor, $to): void {
            $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReportStatus::Open || ! $to->isClosed()) {
                throw new InvalidStatusTransition;
            }

            $locked->forceFill([
                'status' => $to,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();
        });

        Log::info('report.closed', [
            'report' => $report->uuid,
            'actor' => $actor->uuid,
            'actor_role' => $actor->role->value,
            'to' => $to->value,
        ]);

        return $report->refresh();
    }

    /**
     * @throws InvalidStatusTransition
     */
    private function changeStatus(Artisan $artisan, User $actor, ArtisanStatus $to, ?string $reason): Artisan
    {
        $from = $artisan->status;

        DB::transaction(function () use ($artisan, $actor, $to, $reason, &$from): void {
            $locked = Artisan::query()->whereKey($artisan->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStatusTransition;
            }

            $attributes = ['status' => $to, 'status_reason' => $reason];

            if ($to === ArtisanStatus::Validated) {
                $attributes['validated_by'] = $actor->id;
                $attributes['validated_at'] = now();
            }

            if ($to === ArtisanStatus::Rejected) {
                $attributes['validated_by'] = null;
                $attributes['validated_at'] = null;
            }

            $locked->forceFill($attributes)->save();
        });

        Log::info('artisan.status_changed', [
            'artisan' => $artisan->uuid,
            'actor' => $actor->uuid,
            'actor_role' => $actor->role->value,
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]);

        return $artisan->refresh();
    }
}
