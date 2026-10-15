<?php

use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Public search. Loaded by routes/api.php under /api/v1. No account needed.
*/

Route::get('search/artisans', [SearchController::class, 'artisans'])
    ->middleware('throttle:public-api')
    ->name('search.artisans');
