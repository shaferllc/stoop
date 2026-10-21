<?php

use App\Http\Controllers\Api\EmbedController;
use Illuminate\Support\Facades\Route;

// Public JSON for the magazine's article-page boxes (see resources/views/embed.blade.php)...
Route::prefix('embed')->middleware('throttle:120,1,api-embed')->group(function () {
    Route::get('neighborhood/{code}', [EmbedController::class, 'neighborhood'])->where('code', '[A-Za-z0-9]{2,4}');
    Route::get('places/{place}', [EmbedController::class, 'place']);
    Route::get('quotes/{id}', [EmbedController::class, 'quote'])->whereNumber('id')->name('embed.quote');
    Route::get('polls/{id}', [EmbedController::class, 'poll'])->whereNumber('id');
    Route::get('authors/{slug}', [EmbedController::class, 'author']);
});
