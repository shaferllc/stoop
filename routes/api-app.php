<?php

use App\Http\Controllers\Api\App\AuthController;
use App\Http\Controllers\Api\App\DispatchController;
use App\Http\Controllers\Api\App\MagazineController;
use App\Http\Controllers\Api\App\MessagesController;
use App\Http\Controllers\Api\App\NeighborhoodController;
use App\Http\Controllers\Api\App\PeopleController;
use App\Http\Controllers\Api\App\QuizController;
use App\Http\Middleware\EnsureNotBanned;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
 * The JSON API behind the iOS app, under /api/app. Every route but login needs a Sanctum
 * token with the "app" ability (personal "dispatch:write" tokens don't work here).
 * Banned accounts can still see /me (with the ban) and sign out, like the website.
 */
Route::prefix('app')->name('app.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:sanctum', CheckAbilities::class.':app', 'throttle:120,1,app'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::middleware(EnsureNotBanned::class)->group(function () {
            Route::post('rules/accept', [AuthController::class, 'acceptRules']);
            Route::get('config', [DispatchController::class, 'config']);

            Route::get('feed', [DispatchController::class, 'feed']);
            Route::get('saved', [DispatchController::class, 'saved'])->middleware('verified');
            Route::get('dispatches/{id}', [DispatchController::class, 'show'])->whereNumber('id');
            Route::post('dispatches', [DispatchController::class, 'store'])->middleware('throttle:20,1,app-post');
            Route::post('dispatches/{id}/marks/{kind}', [DispatchController::class, 'mark'])->whereNumber('id')->whereIn('kind', ['save', 'been', 'going', 'interested', 'mumbo', 'rally'])->middleware('throttle:60,1,app-mark');
            Route::post('dispatches/{id}/half-smoke', [DispatchController::class, 'halfSmoke'])->whereNumber('id')->middleware('throttle:30,1,app-half-smoke');
            Route::post('dispatches/{id}/vote', [DispatchController::class, 'vote'])->whereNumber('id')->middleware('throttle:30,1,app-vote');
            Route::post('dispatches/{id}/report', [DispatchController::class, 'report'])->whereNumber('id')->middleware('throttle:10,1,app-report');
            Route::get('places', [DispatchController::class, 'places'])->middleware('throttle:60,1,app-places');

            Route::get('users/{username}', [PeopleController::class, 'profile']);
            Route::post('users/{username}/follow', [PeopleController::class, 'follow'])->middleware('throttle:30,1,app-follow');
            Route::post('users/{username}/{type}', [PeopleController::class, 'silence'])->whereIn('type', ['mute', 'block'])->middleware('throttle:10,1,app-silence');
            Route::get('search', [PeopleController::class, 'search'])->middleware('throttle:15,1,app-search');
            Route::get('stations/{code}', [PeopleController::class, 'station'])->middleware('throttle:30,1,app-station');
            Route::get('notifications', [PeopleController::class, 'notifications'])->middleware('verified');

            Route::get('stations/{code}/neighborhood', [NeighborhoodController::class, 'station'])->middleware('throttle:30,1,app-neighborhood');
            Route::get('catch-up', [NeighborhoodController::class, 'catchUp'])->middleware('throttle:10,1,app-catch-up');
            Route::post('compose/suggest', [NeighborhoodController::class, 'suggest'])->middleware('throttle:30,1,app-suggest');

            Route::get('ask-the-editor/{id}', [MagazineController::class, 'thread'])->whereNumber('id');
            Route::post('ask-the-editor/{id}/questions', [MagazineController::class, 'ask'])->whereNumber('id')->middleware(['verified', 'throttle:10,1,app-ama-ask']);
            Route::post('ask-the-editor/{id}/questions/{question}/vote', [MagazineController::class, 'vote'])->whereNumber(['id', 'question'])->middleware(['verified', 'throttle:60,1,app-ama-vote']);
            Route::get('ballots/{id}', [MagazineController::class, 'ballot'])->whereNumber('id');
            Route::post('ballots/{id}/vote', [MagazineController::class, 'ballotVote'])->whereNumber('id')->middleware(['verified', 'throttle:30,1,app-ballot-vote']);

            Route::get('quiz', [QuizController::class, 'show']);
            Route::post('quiz/answer', [QuizController::class, 'answer'])->middleware('throttle:30,1,app-quiz');

            require __DIR__.'/api-app-community.php';
            require __DIR__.'/api-app-sports.php';

            Route::middleware('verified')->group(function () {
                Route::get('conversations', [MessagesController::class, 'index']);
                Route::post('conversations', [MessagesController::class, 'start'])->middleware('throttle:10,1,app-dm-start');
                Route::get('conversations/{id}', [MessagesController::class, 'show'])->whereNumber('id');
                Route::post('conversations/{id}/messages', [MessagesController::class, 'send'])->whereNumber('id')->middleware('throttle:30,1,app-dm-send');
                Route::post('conversations/{id}/accept', [MessagesController::class, 'accept'])->whereNumber('id');
                Route::post('conversations/{id}/decline', [MessagesController::class, 'decline'])->whereNumber('id');
                Route::post('messages/{id}/report', [MessagesController::class, 'report'])->whereNumber('id')->middleware('throttle:10,1,app-dm-report');
            });
        });
    });
});
