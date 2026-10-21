<?php

use App\Livewire\Admin;
use App\Livewire\Pages\HappeningsCalendar;
use App\Livewire\Pages\HuntShow;
use App\Livewire\Pages\HuntsIndex;
use App\Livewire\Pages\LostFound;
use App\Livewire\Pages\MetroBingo;
use App\Models\Challenge;
use App\Models\Contest;
use App\Models\Dispatch;
use App\Models\Game;
use App\Models\Hunt;
use App\Models\User;
use App\Support\Bingo;
use App\Support\DailyQuiz;
use App\Support\Happenings;
use App\Support\ReadersPicks;
use App\Support\Rivalries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Happenings, Lost & found and the city games (Metro Bingo, scavenger hunts, the Line Cup).
 */
Route::livewire('happenings', HappeningsCalendar::class)->name('happenings');
Route::livewire('lost-and-found', LostFound::class)->name('lost-found');
Route::livewire('hunts', HuntsIndex::class)->name('hunts');
Route::livewire('hunts/{hunt:slug}', HuntShow::class)->name('hunts.show');

// One happening as an .ics file.
Route::get('happenings/{dispatch}.ics', function (Dispatch $dispatch) {
    abort_unless($dispatch->type === 'happening' && $dispatch->status === 'approved' && $dispatch->happening?->starts_at, 404);

    return response(Happenings::ics(collect([$dispatch]), 'Stoop'))
        ->header('Content-Type', 'text/calendar; charset=UTF-8')
        ->header('Content-Disposition', 'attachment; filename="stoop-happening-'.$dispatch->id.'.ics"');
})->middleware('throttle:60,1')->name('happenings.ics');

// "Get tickets" counts the click, then sends people to the host's ticket page. No payments here.
Route::get('happenings/{dispatch}/tickets', function (Dispatch $dispatch) {
    $happening = $dispatch->happening;
    abort_unless($dispatch->status === 'approved' && $happening?->ticket_url, 404);
    $happening->increment('ticket_clicks');

    return redirect()->away($happening->ticket_url);
})->middleware('throttle:30,1')->name('happenings.tickets');

// The personal calendar feed. Calendar apps can't log in, so the secret token is the key.
Route::get('calendar/{token}.ics', function (string $token) {
    $userId = DB::table('calendar_feeds')->where('token', $token)->value('user_id');
    abort_unless($userId && $user = User::find($userId), 404);

    return response(Happenings::ics(Happenings::forFeed($user), 'Stoop: '.$user->username))
        ->header('Content-Type', 'text/calendar; charset=UTF-8')->header('Cache-Control', 'private, max-age=900');
})->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('calendar.feed');

// The Line Cup lives on the Rivalries page now.
Route::redirect('line-cup', '/rivalries#line-cup')->name('line-cup');

// Every game in one place.
Route::get('play', function () {
    $user = auth()->user();
    $quizDone = $user && DB::table('quiz_attempts')->where('user_id', $user->id)->where('day', DailyQuiz::today()->toDateString())->whereNotNull('completed_at')->exists();
    $bingo = $user ? Bingo::card($user) : null;
    $hunts = Hunt::running()->count();
    $cup = Rivalries::lineCup(Rivalries::monthStart());

    return view('play', [
        'cards' => [
            ['title' => 'The Daily', 'url' => route('quiz'), 'when' => 'Every day', 'live' => ! $quizDone, 'about' => 'Five DC questions. Keep your streak going.',
                'status' => $user ? ($quizDone ? 'Done for today ✓' : 'Today’s quiz is waiting') : null],
            ['title' => 'Metro Bingo', 'url' => route('bingo'), 'when' => 'This week', 'live' => true, 'about' => 'A new 5×5 card every Monday. Squares fill in as you use Stoop.',
                'status' => $bingo ? collect($bingo['squares'])->where('marked', true)->count().' of 25 marked' : null],
            ['title' => 'Scavenger hunts', 'url' => route('hunts'), 'when' => $hunts ? $hunts.' running' : 'Seasonal', 'live' => $hunts > 0, 'about' => 'Follow clues around the city and collect passport stamps.', 'status' => null],
            ['title' => 'List challenges', 'url' => route('challenges'), 'when' => ($running = Challenge::query()->where('starts_at', '<=', now())->where('ends_at', '>=', now())->count()) ? $running.' running' : 'Seasonal', 'live' => $running > 0, 'about' => 'Eat and drink your way through the magazine’s lists for a badge and a prize.', 'status' => null],
            ['title' => 'Passports', 'url' => route('passports'), 'when' => 'Any time', 'live' => false, 'about' => 'Stamps for the places on the magazine’s lists you’ve been to.', 'status' => null],
            ['title' => 'Rivalries & Line Cup', 'url' => route('rivalries'), 'when' => 'Weekly and monthly', 'live' => true, 'about' => 'Neighborhood matchups every week, and the six lines battle for the monthly cup.',
                'status' => ($leader = $cup->keys()->first()) && $cup->first()['points'] > 0 ? ucfirst($leader).' line leads the cup' : null],
        ],
        'games' => Game::where('starts_at', '>=', now()->subDays(3))->orderBy('starts_at')->take(12)->get(),
        'contests' => Contest::where(fn ($q) => $q->where('closes_at', '>=', now())->orWhere('updated_at', '>=', now()->subMonth()))->latest('closes_at')->get(),
    ]);
})->name('play');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('bingo', MetroBingo::class)->name('bingo');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('hunts', Admin\Hunts::class)->name('hunts');
        Route::livewire('readers-picks', Admin\ReadersPicks::class)->name('readers-picks');
        Route::get('readers-picks.csv', function () {
            abort_unless(auth()->user()->is_staff, 403);
            $list = (string) request('list');
            abort_unless(ReadersPicks::lists()->contains($list), 404);
            $days = in_array((int) request('days'), [30, 90, 365], true) ? (int) request('days') : 90;

            return response()->streamDownload(function () use ($list, $days) {
                $out = fopen('php://output', 'w');
                foreach (ReadersPicks::csv($list, $days) as $row) {
                    fputcsv($out, $row, escape: '');
                }
                fclose($out);
            }, 'readers-picks-'.str($list)->slug().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        })->middleware('throttle:10,1')->name('readers-picks.csv');
    });
});
