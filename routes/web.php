<?php

use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Moderation;
use App\Livewire\Pages\Notifications;
use App\Livewire\Pages\Saved;
use App\Livewire\Pages\ShowDispatch;
use App\Livewire\Pages\UserProfile;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\ShareImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Guests can read the public feeds, threads and profiles...
Route::livewire('/', Feed::class)->name('home');
Route::livewire('d/{dispatch}', ShowDispatch::class)->name('dispatches.show');
Route::livewire('@{user:username}', UserProfile::class)->name('users.show');
Route::view('rules', 'rules')->name('rules');

// Share images for link previews, cached for a day...
Route::get('d/{dispatch}/og.png', function (Dispatch $dispatch) {
    abort_unless($dispatch->status === 'approved', 404);

    return response(Cache::remember("og:d:{$dispatch->id}", now()->addDay(), fn () => ShareImage::forDispatch($dispatch)))
        ->header('Content-Type', 'image/png')->header('Cache-Control', 'public, max-age=86400');
})->name('dispatches.og');

Route::get('@{user:username}/card', fn (User $user) => view('partials.hover-card', [
    'user' => $user->loadCount(['followers', 'following']),
]))->middleware('throttle:120,1')->name('users.card');

Route::get('@{user:username}/og.png', fn (User $user) => response(Cache::remember("og:u:{$user->id}", now()->addDay(), fn () => ShareImage::forUser($user)))
    ->header('Content-Type', 'image/png')->header('Cache-Control', 'public, max-age=86400'))->name('users.og');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/')->name('dashboard');
    Route::livewire('saved', Saved::class)->name('saved');
    Route::livewire('notifications', Notifications::class)->name('notifications');
    Route::livewire('moderation', Moderation::class)->name('moderation');
});

require __DIR__.'/settings.php';
