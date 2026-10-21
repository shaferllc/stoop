<?php

use App\Http\Controllers\Api\App\SportsController;
use Illuminate\Support\Facades\Route;

/*
 * DC sports for the app. Required inside the signed-in, not-banned group in api-app.php.
 */
Route::get('sports/today', [SportsController::class, 'today'])->middleware('throttle:60,1,app-sports');
Route::get('sports/teams/{team}', [SportsController::class, 'team'])->where('team', '[a-z-]+')->middleware('throttle:60,1,app-sports');
Route::get('sports/favorites', [SportsController::class, 'favorites']);
Route::put('sports/favorites', [SportsController::class, 'setFavorites'])->middleware('throttle:20,1,app-sports-fav');
Route::post('sports/watch-parties', [SportsController::class, 'watchParty'])->middleware('throttle:20,1,app-sports-fav');
