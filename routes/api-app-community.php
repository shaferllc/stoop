<?php

use App\Http\Controllers\Api\App\GamesController;
use App\Http\Controllers\Api\App\HappeningsController;
use App\Http\Controllers\Api\App\PlaceDealsController;
use Illuminate\Support\Facades\Route;

/*
 * Happenings, Lost & found, owners' deals and the city games for the app. Required inside
 * the signed-in, not-banned group in api-app.php, so those rules apply here too.
 */
Route::get('happenings', [HappeningsController::class, 'index'])->middleware('throttle:60,1,app-happenings');
Route::get('happenings/{id}/rsvps', [HappeningsController::class, 'rsvps'])->whereNumber('id');
Route::post('happenings/{id}', [HappeningsController::class, 'update'])->whereNumber('id')->middleware('throttle:20,1,app-happening-edit');
Route::post('calendar-feed', [HappeningsController::class, 'feed'])->middleware('throttle:10,1,app-calendar');
Route::get('lost-and-found', [HappeningsController::class, 'lostFound'])->middleware('throttle:60,1,app-lost');
Route::post('dispatches/{id}/reunited', [HappeningsController::class, 'reunited'])->whereNumber('id')->middleware('throttle:20,1,app-lost');

Route::get('deals', [PlaceDealsController::class, 'index'])->middleware('throttle:60,1,app-deals');
Route::post('places/{id}/deals', [PlaceDealsController::class, 'store'])->whereNumber('id')->middleware(['verified', 'throttle:10,1,app-deals-post']);
Route::post('deals/{id}/end', [PlaceDealsController::class, 'end'])->whereNumber('id')->middleware('throttle:10,1,app-deals-post');

Route::get('bingo', [GamesController::class, 'bingo']);
Route::post('bingo/mark', [GamesController::class, 'markBingo'])->middleware('throttle:30,1,app-bingo');
Route::get('hunts', [GamesController::class, 'hunts']);
Route::get('hunts/{slug}', [GamesController::class, 'hunt']);
Route::post('hunts/{slug}/steps/{id}/check-in', [GamesController::class, 'checkIn'])->whereNumber('id')->middleware('throttle:10,1,app-hunt');
Route::get('line-cup', [GamesController::class, 'lineCup']);
