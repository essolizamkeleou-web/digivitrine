<?php

use App\Http\Controllers\Api\V1\Admin\AdminArtisanController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use Illuminate\Support\Facades\Route;

/*
| Staff endpoints. Loaded by routes/api.php under /api/v1.
| Active admin or moderator accounts only: 401 without a token, 403 for any other role.
| A staff member cannot validate, reject or suspend their own profile (ArtisanPolicy::moderate).
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'active', 'role:admin,moderator', 'throttle:api'])
    ->group(function (): void {
        Route::get('artisans', [AdminArtisanController::class, 'index'])->name('artisans.index');

        Route::get('artisans/{artisan:uuid}', [AdminArtisanController::class, 'show'])
            ->whereUuid('artisan')
            ->name('artisans.show');

        Route::patch('artisans/{artisan:uuid}/validate', [AdminArtisanController::class, 'validate'])
            ->whereUuid('artisan')
            ->name('artisans.validate');

        Route::patch('artisans/{artisan:uuid}/reject', [AdminArtisanController::class, 'reject'])
            ->whereUuid('artisan')
            ->name('artisans.reject');

        Route::patch('artisans/{artisan:uuid}/suspend', [AdminArtisanController::class, 'suspend'])
            ->whereUuid('artisan')
            ->name('artisans.suspend');

        Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');

        Route::patch('reports/{report:uuid}', [AdminReportController::class, 'update'])
            ->whereUuid('report')
            ->name('reports.update');
    });
