<?php

use App\Http\Controllers\Api\V1\PhotoController;
use Illuminate\Support\Facades\Route;

/*
| Photo gallery of an artisan profile. Loaded by routes/api.php under /api/v1.
| Reading is public (a bearer token only reveals hidden photos to the owner and the staff);
| changing the gallery needs an active account allowed to edit the profile.
| A photo of another profile in the URL gives a 404 (scoped bindings).
*/

Route::get('artisans/{artisan:uuid}/photos', [PhotoController::class, 'index'])
    ->whereUuid('artisan')
    ->middleware('throttle:public-api')
    ->name('artisans.photos.index');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->scopeBindings()->group(function (): void {
    Route::post('artisans/{artisan:uuid}/photos', [PhotoController::class, 'store'])
        ->whereUuid('artisan')
        ->middleware('throttle:30,60')
        ->name('artisans.photos.store');

    Route::patch('artisans/{artisan:uuid}/photos/{photo:uuid}', [PhotoController::class, 'update'])
        ->whereUuid(['artisan', 'photo'])
        ->name('artisans.photos.update');

    Route::delete('artisans/{artisan:uuid}/photos/{photo:uuid}', [PhotoController::class, 'destroy'])
        ->whereUuid(['artisan', 'photo'])
        ->name('artisans.photos.destroy');
});
