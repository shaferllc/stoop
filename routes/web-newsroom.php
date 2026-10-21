<?php

use App\Livewire\Admin;
use App\Livewire\Pages\TipCreate;
use App\Models\Issue;
use App\Models\Tip;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Newsroom tools: tips, story radar, callout quotes, the archive and issue drops.

Route::get('issues/{issue:slug}', function (Issue $issue) {
    abort_unless($issue->published_at || auth()->user()?->is_staff, 404);

    return view('issue', ['issue' => $issue]);
})->middleware('throttle:60,1')->name('issues.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('tips/new', TipCreate::class)->name('tips.create');

    Route::prefix('admin')->name('admin')->group(function () {
        Route::livewire('tips', Admin\Tips::class)->name('.tips');
        Route::livewire('radar', Admin\Radar::class)->name('.radar');
        Route::livewire('callouts', Admin\Callouts::class)->name('.callouts');
        Route::livewire('archive', Admin\Archive::class)->name('.archive');
        Route::livewire('issues', Admin\Issues::class)->name('.issues');

        // Tip photos live on the private disk; only staff can open them.
        Route::get('tips/{tip}/photos/{n}', function (Tip $tip, int $n) {
            abort_unless(auth()->user()->is_staff, 403);
            abort_unless($path = $tip->photos[$n] ?? null, 404);

            return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=3600']);
        })->whereNumber('n')->name('.tips.photo');
    });
});
