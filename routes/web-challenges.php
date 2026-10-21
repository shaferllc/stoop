<?php

use App\Livewire\Admin;
use App\Livewire\Pages\ChallengeShow;
use App\Livewire\Pages\ChallengesIndex;
use App\Models\Challenge;
use App\Support\Audit;
use App\Support\Challenges;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

// List challenges on the magazine's lists, and the magazine's events import.

Route::livewire('challenges', ChallengesIndex::class)->name('challenges');
Route::livewire('challenges/{challenge:slug}', ChallengeShow::class)->name('challenges.show');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('challenges', Admin\Challenges::class)->name('challenges');
    Route::livewire('magazine-events', Admin\MagazineEvents::class)->name('magazine-events');

    // Finishers with their email, so editors can send prizes by hand.
    Route::get('challenges/{challenge}/finishers.csv', function (Challenge $challenge) {
        abort_unless(auth()->user()->hasRole(Roles::PAGES['admin.challenges.csv']), 403);
        Audit::log('challenge.finishers_exported', $challenge);

        return response()->streamDownload(function () use ($challenge) {
            $out = fopen('php://output', 'w');
            foreach (Challenges::csv($challenge) as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, 'challenge-'.$challenge->slug.'-finishers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    })->middleware('throttle:10,1')->name('challenges.csv');
});
