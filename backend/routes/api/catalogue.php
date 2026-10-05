<?php

use App\Http\Controllers\Api\V1\Catalogue\CategoryController;
use App\Http\Controllers\Api\V1\Catalogue\CityController;
use App\Http\Controllers\Api\V1\Catalogue\NeighborhoodController;
use Illuminate\Support\Facades\Route;

/*
| Public reference data (no account needed). Loaded by routes/api.php under /api/v1.
| Slugs only: internal identifiers are never exposed.
*/

$slug = '[a-z0-9-]+';

Route::middleware('throttle:public-api')->group(function () use ($slug): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])
        ->where('category', $slug)->name('categories.show');
    Route::get('categories/{category}/subcategories', [CategoryController::class, 'subcategories'])
        ->where('category', $slug)->name('categories.subcategories');

    Route::get('cities', [CityController::class, 'index'])->name('cities.index');
    Route::get('cities/{city}', [CityController::class, 'show'])
        ->where('city', $slug)->name('cities.show');
    Route::get('cities/{city}/neighborhoods', [CityController::class, 'neighborhoods'])
        ->where('city', $slug)->name('cities.neighborhoods');

    Route::get('neighborhoods', [NeighborhoodController::class, 'index'])->name('neighborhoods.index');
});
