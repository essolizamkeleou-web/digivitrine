<?php

use App\Http\Controllers\Api\V1\CommuneController;
use Illuminate\Support\Facades\Route;

/*
| Public communes of Greater Lome. Loaded by routes/api.php under /api/v1. No account needed.
*/

Route::get('communes', [CommuneController::class, 'index'])
    ->middleware('throttle:public-api')
    ->name('communes.index');

Route::get('communes/{commune}', [CommuneController::class, 'show'])
    ->where('commune', '[a-z0-9]+(-[a-z0-9]+)*')
    ->middleware('throttle:public-api')
    ->name('communes.show');

Route::get('communes/{commune}/neighborhoods', [CommuneController::class, 'neighborhoods'])
    ->where('commune', '[a-z0-9]+(-[a-z0-9]+)*')
    ->middleware('throttle:public-api')
    ->name('communes.neighborhoods');
