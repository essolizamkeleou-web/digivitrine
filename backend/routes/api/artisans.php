<?php

use App\Http\Controllers\Api\V1\ArtisanController;
use Illuminate\Support\Facades\Route;

/*
| Artisan profiles. Loaded by routes/api.php under /api/v1.
| Identified by uuid. Reading is public (a bearer token is optional and only reveals the
| caller's own non-public profiles); writing needs an active account.
*/

Route::get('artisans', [ArtisanController::class, 'index'])
    ->middleware('throttle:public-api')
    ->name('artisans.index');

Route::get('artisans/{artisan:uuid}', [ArtisanController::class, 'show'])
    ->whereUuid('artisan')
    ->middleware('throttle:public-api')
    ->name('artisans.show');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
    Route::post('artisans', [ArtisanController::class, 'store'])->name('artisans.store');

    Route::match(['put', 'patch'], 'artisans/{artisan:uuid}', [ArtisanController::class, 'update'])
        ->whereUuid('artisan')
        ->name('artisans.update');

    Route::post('artisans/{artisan:uuid}/submit', [ArtisanController::class, 'submit'])
        ->whereUuid('artisan')
        ->name('artisans.submit');
});
