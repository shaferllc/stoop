<?php

use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\SubscriptionWebhookController;
use Illuminate\Support\Facades\Route;

// Public, read-only...
Route::middleware('throttle:60,1')->group(function () {
    Route::get('dispatches', [DispatchController::class, 'index']);
    Route::get('dispatches/{id}', [DispatchController::class, 'show'])->whereNumber('id');
    Route::get('users/{user:username}', [DispatchController::class, 'user']);
    Route::get('discussions', [DispatchController::class, 'discussions']);
});

// The magazine's subscription system, signed with a shared secret...
Route::post('subscriptions', SubscriptionWebhookController::class)->middleware('throttle:120,1');

// Posting with a personal token from Settings → Terminal & API...
Route::post('dispatches', [DispatchController::class, 'store'])->middleware(['auth:sanctum', 'throttle:20,1']);

require __DIR__.'/api-admin.php';
