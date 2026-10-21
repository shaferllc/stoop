<?php

use App\Livewire\Pages\SportsIndex;
use App\Livewire\Pages\SportsTeam;
use App\Models\Game;
use App\Support\Metro;
use Illuminate\Support\Facades\Route;

/*
 * DC sports: today's games and standings, team pages, and one page per game with the
 * venue's trains and watch parties. Tickets link out to the team; Stoop sells nothing.
 */
Route::livewire('sports', SportsIndex::class)->name('sports');

Route::get('sports/games/{game}', function (Game $game) {
    $team = $game->teamConfig();
    // Live trains from three hours before the start until the crowd has gone home.
    $nearGameTime = now()->between($game->starts_at->copy()->subHours(3), $game->starts_at->copy()->addHours(4));

    return view('sports-game', [
        'game' => $game,
        'team' => $team,
        'trains' => $game->stop && $nearGameTime ? Metro::nextTrains($game->stop) : null,
        'nearGameTime' => $nearGameTime,
        'parties' => $game->watchParties()->where('dispatches.status', 'approved')->with(['user', 'happening'])->get(),
    ]);
})->whereNumber('game')->middleware('throttle:60,1')->name('sports.game');

Route::livewire('sports/{team}', SportsTeam::class)->where('team', '[a-z-]+')->name('sports.team');
