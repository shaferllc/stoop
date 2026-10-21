<?php

use App\Http\Controllers\Api\EditorialController as Editorial;
use Illuminate\Support\Facades\Route;

// Newsroom tools in the Stoop Admin desktop app. Required inside the /api/admin group.
Route::get('radar', [Editorial::class, 'radar']);
Route::post('radar/idea', [Editorial::class, 'idea'])->middleware('throttle:20,1');
Route::get('tips', [Editorial::class, 'tips']);
Route::get('editors', [Editorial::class, 'editors']);
Route::post('tips/{tip}', [Editorial::class, 'updateTip'])->middleware('throttle:60,1');
Route::post('tips/{tip}/reply', [Editorial::class, 'replyToTip'])->middleware('throttle:20,1');
Route::get('callouts', [Editorial::class, 'callouts']);
Route::get('callouts/{dispatch}/quotes', [Editorial::class, 'quotes']);
