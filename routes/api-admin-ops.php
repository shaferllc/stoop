<?php

use App\Bots\Sentinel;
use App\Http\Controllers\Api\AdminOpsController as Ops;
use App\Support\ClaudeText;
use Illuminate\Support\Facades\Route;

// Bots, spam and operations for the Stoop Admin desktop app. Required inside the
// /api/admin group in routes/api-admin.php, so the same auth, throttle and role checks apply...
Route::get('spam-clusters', [Ops::class, 'clusters']);
Route::post('spam-clusters/detect', [Ops::class, 'detect'])->middleware('throttle:6,1');
Route::post('spam-clusters/{cluster}/act', [Ops::class, 'actOnCluster']);
Route::post('spam-clusters/{cluster}/dismiss', [Ops::class, 'dismissCluster']);

Route::post('users/bulk', [Ops::class, 'bulk'])->middleware('throttle:20,1');
Route::get('users/{user}/risk', [Ops::class, 'risk']);
Route::post('users/{user}/check', [Ops::class, 'check'])->middleware('throttle:10,1');

Route::get('bots/answers', [Ops::class, 'answers']);
Route::post('bots/answers/{answer}/wrong', [Ops::class, 'markWrong'])->whereNumber('answer');
Route::post('bots/answers/{answer}/correct', [Ops::class, 'correct'])->whereNumber('answer')->middleware('throttle:10,1');
Route::get('bots/knowledge', [Ops::class, 'knowledge']);
Route::post('bots/knowledge', [Ops::class, 'saveKnowledge']);
Route::delete('bots/knowledge/{entry}', [Ops::class, 'deleteKnowledge'])->whereNumber('entry');

Route::get('activity', [Ops::class, 'activity']);

// @sentinel's hourly moderation summaries, newest first.
Route::get('sentinel', fn () => ['claude' => ClaudeText::enabled(), 'summaries' => Sentinel::latest()]);

Route::get('roles', [Ops::class, 'roles']);
Route::post('roles', [Ops::class, 'setRoles']);
