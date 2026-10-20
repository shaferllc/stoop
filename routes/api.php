<?php

use App\Http\Controllers\Api\DispatchController;
use Illuminate\Support\Facades\Route;

// Public, read-only...
Route::middleware('throttle:60,1')->group(function () {
    Route::get('dispatches', [DispatchController::class, 'index']);
    Route::get('dispatches/{id}', [DispatchController::class, 'show'])->whereNumber('id');
    Route::get('users/{user:username}', [DispatchController::class, 'user']);
    Route::get('discussions', [DispatchController::class, 'discussions']);
});

// Posting with a personal token from Settings → Terminal & API...
Route::post('dispatches', [DispatchController::class, 'store'])->middleware(['auth:sanctum', 'throttle:20,1']);
