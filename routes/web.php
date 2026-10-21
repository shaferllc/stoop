<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\TransparencyController;
use App\Livewire\Admin;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\ConversationView;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Inbox;
use App\Livewire\Pages\Moderation;
use App\Livewire\Pages\Notifications;
use App\Livewire\Pages\PlaceShow;
use App\Livewire\Pages\PlacesIndex;
use App\Livewire\Pages\Saved;
use App\Livewire\Pages\Search;
use App\Livewire\Pages\ShowDispatch;
use App\Livewire\Pages\UserProfile;
use App\Mail\WeeklyDigest;
use App\Models\Dispatch;
use App\Models\MessageImage;
use App\Models\Sponsorship;
use App\Models\User;
use App\Support\Newsletter;
use App\Support\RidershipGraph;
use App\Support\ShareImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Guests can read the public feeds, threads and profiles...
Route::livewire('/', Feed::class)->name('home');
Route::livewire('d/{dispatch}', ShowDispatch::class)->name('dispatches.show');
Route::livewire('@{user:username}', UserProfile::class)->name('users.show');
Route::view('rules', 'rules')->name('rules');
Route::view('privacy', 'privacy')->name('privacy');
Route::view('terms', 'terms')->name('terms');
Route::livewire('search', Search::class)->name('search');
Route::livewire('places', PlacesIndex::class)->name('places');
Route::livewire('places/{place}', PlaceShow::class)->name('places.show');

// Sponsored placements count a click, then send people on to the sponsor.
Route::get('s/{sponsorship}', function (Sponsorship $sponsorship) {
    $sponsorship->increment('clicks');

    return redirect()->away($sponsorship->url ?: ($sponsorship->place ? route('places.show', $sponsorship->place) : route('home')));
})->middleware('throttle:60,1')->name('sponsorships.click');
Route::get('stops/{code}', function (string $code) {
    abort_unless(config('stoop.stations.'.strtoupper($code)), 404);

    return redirect()->route('home', ['stop' => strtoupper($code)]);
})->name('stops.show');
Route::get('transparency', TransparencyController::class)->name('transparency');

// The weekly email's one-click unsubscribe link...
Route::match(['get', 'post'], 'newsletter/unsubscribe/{user}', function (User $user) {
    $user->forceFill(['newsletter' => false])->save();

    return response('You’re unsubscribed from the weekly Stoop email.', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->middleware('signed')->name('newsletter.unsubscribe')->withoutMiddleware(ValidateCsrfToken::class);

// The embed for magazine article pages...
Route::get('embed.js', fn () => response()->view('embed')->header('Content-Type', 'application/javascript; charset=UTF-8')->header('Cache-Control', 'public, max-age=3600'))->name('embed');

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
    Route::redirect('operations', '/admin')->name('operations');

    Route::prefix('admin')->name('admin')->group(function () {
        Route::livewire('/', Admin\Dashboard::class);
        Route::livewire('people', Admin\Users::class)->name('.users');
        Route::livewire('people/{user}', Admin\UserShow::class)->name('.users.show');
        Route::livewire('places', Admin\Places::class)->name('.places');
        Route::livewire('sponsorships', Admin\Sponsorships::class)->name('.sponsorships');
        Route::livewire('content', Admin\Content::class)->name('.content');
        Route::get('newsletter/preview', function () {
            abort_unless(auth()->user()->is_staff, 403);

            return new WeeklyDigest(Newsletter::digest(), null);
        })->name('.newsletter.preview');
        Route::livewire('audit', Admin\AuditLog::class)->name('.audit');
    });
    Route::get('settings/export', ExportController::class)->middleware('throttle:5,1')->name('export');
    Route::livewire('messages', Inbox::class)->name('messages');
    Route::livewire('messages/{conversation}', ConversationView::class)->name('messages.show');
    Route::get('messages/images/{image}', function (MessageImage $image) {
        abort_unless($image->message->conversation->participants()->whereKey(auth()->id())->exists(), 404);

        return response()->file(Storage::disk('local')->path($image->path), ['Cache-Control' => 'private, max-age=86400']);
    })->name('messages.image');
    Route::get('banned', fn () => view('banned', ['ban' => auth()->user()->activeBan()]))->name('banned');
});

require __DIR__.'/settings.php';
