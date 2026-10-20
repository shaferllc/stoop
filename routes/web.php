<?php

use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Moderation;
use App\Livewire\Pages\Notifications;
use App\Livewire\Pages\Saved;
use App\Livewire\Pages\ShowDispatch;
use App\Livewire\Pages\UserProfile;
use Illuminate\Support\Facades\Route;

// Guests can read the public feeds, threads and profiles...
Route::livewire('/', Feed::class)->name('home');
Route::livewire('d/{dispatch}', ShowDispatch::class)->name('dispatches.show');
Route::livewire('@{user:username}', UserProfile::class)->name('users.show');
Route::view('rules', 'rules')->name('rules');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/')->name('dashboard');
    Route::livewire('saved', Saved::class)->name('saved');
    Route::livewire('notifications', Notifications::class)->name('notifications');
    Route::livewire('moderation', Moderation::class)->name('moderation');
});

require __DIR__.'/settings.php';
