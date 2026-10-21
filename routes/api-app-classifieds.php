<?php

use App\Http\Controllers\Api\App\ClassifiedsController;
use Illuminate\Support\Facades\Route;

/*
 * Classifieds for the app. Required inside the signed-in, not-banned group in api-app.php.
 */
Route::get('classifieds', [ClassifiedsController::class, 'index'])->middleware('throttle:60,1,app-classifieds');
Route::get('classifieds/{id}', [ClassifiedsController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,app-classifieds');
Route::post('classifieds', [ClassifiedsController::class, 'store'])->middleware(['verified', 'throttle:10,1,app-classifieds-post']);
Route::post('classifieds/{id}/renew', [ClassifiedsController::class, 'renew'])->whereNumber('id')->middleware('throttle:10,1,app-classifieds-post');
Route::post('classifieds/{id}/filled', [ClassifiedsController::class, 'filled'])->whereNumber('id')->middleware('throttle:10,1,app-classifieds-post');
Route::post('classifieds/{id}/contact', [ClassifiedsController::class, 'contact'])->whereNumber('id')->middleware(['verified', 'throttle:10,1,app-classifieds-contact']);
Route::post('classifieds/{id}/report', [ClassifiedsController::class, 'report'])->whereNumber('id')->middleware('throttle:10,1,app-classifieds-report');
