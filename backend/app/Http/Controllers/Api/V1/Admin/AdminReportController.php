<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReportStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Http\Requests\Api\V1\ListReportsRequest;
use App\Http\Requests\Api\V1\UpdateReportRequest;
use App\Http\Resources\AdminReportResource;
use App\Models\Report;
use App\Services\ModerationService;
use App\Support\ApiMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminReportController
{
    public function __construct(private ModerationService $moderation) {}

    public function index(ListReportsRequest $request): AnonymousResourceCollection
    {
        $params = $request->validated();
        $status = $params['status'] ?? null;

        $reports = Report::query()
            ->with([
                'artisan' => fn ($query) => $query->withTrashed(),
                'reporter',
                'resolver',
            ])
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($params['reason'] ?? null, fn (Builder $query, string $value) => $query->where('reason', $value))
            ->when($params['artisan'] ?? null, fn (Builder $query, string $uuid) => $query
                ->whereHas('artisan', fn (Builder $artisan) => $artisan->withTrashed()->where('uuid', $uuid)))
            // Moderation queue: the oldest open reports first; otherwise the newest first.
            ->orderBy('created_at', $status === 'open' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($params['per_page'] ?? 20)
            ->withQueryString();

        return AdminReportResource::collection($reports);
    }

    public function update(UpdateReportRequest $request, Report $report): AdminReportResource|JsonResponse
    {
        try {
            $closed = $this->moderation->closeReport($report, $request->user(), ReportStatus::from($request->validated('status')));
        } catch (InvalidStatusTransition) {
            return response()->json(['message' => ApiMessages::INVALID_TRANSITION], 409);
        }

        return AdminReportResource::make($closed->load([
            'artisan' => fn ($query) => $query->withTrashed(),
            'reporter',
            'resolver',
        ]));
    }
}
