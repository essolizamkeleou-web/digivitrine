<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DigiVitrine REST API, version 1 (served under /api/v1, see ADR-03 and ADR-14)
|--------------------------------------------------------------------------
|
| Domains to add in the next phases, each in its own route group:
| users, artisans, categories, subcategories, cities, neighborhoods,
| photos, services, reports, favorites, reviews.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)
        ->middleware('throttle:public-api')
        ->name('health');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register')
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login')
            ->name('login');

        Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Domain route files
|--------------------------------------------------------------------------
|
| Every file of routes/api/ is loaded (alphabetical order) under /api/v1.
| A new API domain only needs a new file there.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    $files = glob(__DIR__.'/api/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        require $file;
    }
});
