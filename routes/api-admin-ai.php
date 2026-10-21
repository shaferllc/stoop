<?php

use App\Http\Controllers\Api\AdminAiController as Ai;
use Illuminate\Support\Facades\Route;

// Moderator help in the Stoop Admin app. Required inside the /api/admin group, so it shares its auth.
Route::prefix('ai')->group(function () {
    Route::get('queue', [Ai::class, 'queue']);
    Route::get('insights', [Ai::class, 'insights']);

    // These can call Claude.
    Route::middleware('throttle:30,1,ai')->group(function () {
        Route::post('dispatches/{dispatch}/suggest', [Ai::class, 'suggest']);
        Route::get('appeals/{appeal}', [Ai::class, 'appeal']);
        Route::get('users/{user}/brief', [Ai::class, 'brief'])->whereNumber('user');
    });

    Route::get('watch-phrases', [Ai::class, 'watchPhrases']);
    Route::post('watch-phrases', [Ai::class, 'savePhrase']);
    Route::put('watch-phrases/{id}', [Ai::class, 'savePhrase'])->whereNumber('id');
    Route::delete('watch-phrases/{id}', [Ai::class, 'deletePhrase'])->whereNumber('id');
});
