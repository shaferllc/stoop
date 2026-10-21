<?php

use App\Http\Controllers\Api\AdminController as Admin;
use App\Http\Middleware\StaffRole;
use Illuminate\Support\Facades\Route;

// The Stoop Admin desktop app. Staff only, with a token made with the "admin" ability,
// and a staff role that covers the path (App\Support\Roles::API)...
Route::prefix('admin')->middleware(['auth:sanctum', 'throttle:240,1', StaffRole::class])->group(function () {
    Route::get('me', [Admin::class, 'me']);
    Route::get('attention', [Admin::class, 'attention']);
    Route::get('stats', [Admin::class, 'stats']);

    Route::get('queue', [Admin::class, 'queue']);
    Route::post('dispatches/{dispatch}/approve', [Admin::class, 'approve']);
    Route::post('dispatches/{dispatch}/remove', [Admin::class, 'remove']);
    Route::get('appeals', [Admin::class, 'appeals']);
    Route::post('appeals/{appeal}/decide', [Admin::class, 'decide']);
    Route::get('message-reports', [Admin::class, 'messageReports']);
    Route::post('message-reports/{report}/resolve', [Admin::class, 'resolveMessageReport']);
    Route::get('penalties', [Admin::class, 'penalties']);
    Route::post('penalties/{penalty}/lift', [Admin::class, 'lift']);

    Route::get('users', [Admin::class, 'users']);
    Route::get('users/{user}', [Admin::class, 'user'])->whereNumber('user');
    Route::post('users/{user}/penalize', [Admin::class, 'penalize']);
    Route::post('users/{user}/verify', [Admin::class, 'verify']);
    Route::post('users/{user}/notes', [Admin::class, 'note']);

    Route::get('health', [Admin::class, 'health']);
    Route::post('maintenance/{task}', [Admin::class, 'run'])->middleware('throttle:10,1');
    Route::get('log', [Admin::class, 'log']);
    Route::get('failed-jobs', [Admin::class, 'failedJobs']);
    Route::post('failed-jobs/{uuid}/retry', [Admin::class, 'retryJob']);
    Route::delete('failed-jobs/{uuid}', [Admin::class, 'forgetJob']);
    Route::get('audit', [Admin::class, 'audit']);

    require __DIR__.'/api-admin-ops.php';
});
