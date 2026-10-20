<?php

use App\Http\Controllers\FeedController;
use App\Http\Controllers\TransparencyController;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Moderation;
use App\Livewire\Pages\Notifications;
use App\Livewire\Pages\Saved;
use App\Livewire\Pages\ShowDispatch;
use App\Livewire\Pages\UserProfile;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\RidershipGraph;
use App\Support\ShareImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Guests can read the public feeds, threads and profiles...
Route::livewire('/', Feed::class)->name('home');
Route::livewire('d/{dispatch}', ShowDispatch::class)->name('dispatches.show');
Route::livewire('@{user:username}', UserProfile::class)->name('users.show');
Route::view('rules', 'rules')->name('rules');
Route::get('transparency', TransparencyController::class)->name('transparency');

// Terminal posting script and Atom feeds...
Route::get('dispatch', fn () => response()->view('terminal')->header('Content-Type', 'text/plain; charset=UTF-8'))->name('terminal');
Route::get('feeds/latest.atom', [FeedController::class, 'latest'])->name('feeds.latest');
Route::get('feeds/line/{line}.atom', [FeedController::class, 'line'])->name('feeds.line');
Route::get('feeds/stop/{code}.atom', [FeedController::class, 'stop'])->name('feeds.stop');
Route::get('@{user:username}/feed.atom', [FeedController::class, 'user'])->name('feeds.user');

// Share images for link previews, cached for a day...
Route::get('d/{dispatch}/og.png', function (Dispatch $dispatch) {
    abort_unless($dispatch->status === 'approved', 404);

    return response(Cache::remember("og:d:{$dispatch->id}", now()->addDay(), fn () => ShareImage::forDispatch($dispatch)))
        ->header('Content-Type', 'image/png')->header('Cache-Control', 'public, max-age=86400');
})->name('dispatches.og');

Route::get('@{user:username}/card', fn (User $user) => view('partials.hover-card', [
    'user' => $user->loadCount(['followers', 'following']),
]))->middleware('throttle:120,1')->name('users.card');

Route::get('@{user:username}/ridership.svg', fn (User $user) => response(Cache::remember(
    "ridership:{$user->id}:".request('theme', 'light'), now()->addHour(), fn () => RidershipGraph::svg($user, request('theme') === 'dark' ? 'dark' : 'light'),
))->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'public, max-age=3600'))->middleware('throttle:60,1')->name('users.ridership');

Route::get('@{user:username}/og.png', fn (User $user) => response(Cache::remember("og:u:{$user->id}", now()->addDay(), fn () => ShareImage::forUser($user)))
    ->header('Content-Type', 'image/png')->header('Cache-Control', 'public, max-age=86400'))->name('users.og');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/')->name('dashboard');
    Route::livewire('saved', Saved::class)->name('saved');
    Route::livewire('notifications', Notifications::class)->name('notifications');
    Route::livewire('moderation', Moderation::class)->name('moderation');
    Route::livewire('appeal', AppealCreate::class)->name('appeals.create');
    Route::get('banned', fn () => view('banned', ['ban' => auth()->user()->activeBan()]))->name('banned');
});

require __DIR__.'/settings.php';
