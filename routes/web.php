<?php

use App\Bots\Bot;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\TransparencyController;
use App\Livewire\Admin;
use App\Livewire\Pages\AmaShow;
use App\Livewire\Pages\AppealCreate;
use App\Livewire\Pages\AuthorShow;
use App\Livewire\Pages\BallotShow;
use App\Livewire\Pages\ContestShow;
use App\Livewire\Pages\ConversationView;
use App\Livewire\Pages\EventShow;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\Inbox;
use App\Livewire\Pages\Moderation;
use App\Livewire\Pages\Notifications;
use App\Livewire\Pages\PassportsPage;
use App\Livewire\Pages\PlaceShow;
use App\Livewire\Pages\PlacesIndex;
use App\Livewire\Pages\Quiz;
use App\Livewire\Pages\Saved;
use App\Livewire\Pages\Search;
use App\Livewire\Pages\ShowDispatch;
use App\Livewire\Pages\UserProfile;
use App\Mail\WeeklyDigest;
use App\Models\Ballot;
use App\Models\Dispatch;
use App\Models\Game;
use App\Models\MessageImage;
use App\Models\QuoteRequest;
use App\Models\Sponsorship;
use App\Models\User;
use App\Support\Ads;
use App\Support\Audit;
use App\Support\Newsletter;
use App\Support\QuoteRequests;
use App\Support\RidershipGraph;
use App\Support\Rivalries;
use App\Support\Settings;
use App\Support\ShareImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Guests can read the public feeds, threads and profiles...
Route::livewire('/', Feed::class)->name('home');
Route::livewire('d/{dispatch}', ShowDispatch::class)->name('dispatches.show');
// The latest few replies, shown under a card when someone taps "Show replies" in a feed.
Route::get('d/{dispatch}/replies-preview', function (Dispatch $dispatch) {
    abort_unless(Dispatch::visibleTo(auth()->user())->whereKey($dispatch->id)->exists(), 404);
    $replies = $dispatch->replies()->visibleTo(auth()->user())->with('user')->withCount('replies')->latest()->take(3)->get()->reverse();

    return view('partials.reply-preview', ['dispatch' => $dispatch, 'replies' => $replies, 'total' => $dispatch->replies()->visibleTo(auth()->user())->count()]);
})->middleware('throttle:120,1')->name('dispatches.replies-preview');
Route::livewire('@{user:username}', UserProfile::class)->name('users.show');
Route::view('rules', 'rules')->name('rules');

// Local development only: one-click sign-in through a signed link that expires (see `php artisan stoop:login-link`).
if (app()->isLocal()) {
    Route::get('dev/login/{user}', function (User $user) {
        auth()->login($user);
        request()->session()->regenerate();

        return redirect('/');
    })->middleware(['signed:relative', 'throttle:20,1'])->name('dev.login');
}
Route::get('bots', fn () => view('bots', ['bots' => Bot::all()]))->name('bots');
Route::view('privacy', 'privacy')->name('privacy');
Route::view('terms', 'terms')->name('terms');
Route::livewire('search', Search::class)->name('search');
Route::livewire('places', PlacesIndex::class)->name('places');
Route::livewire('passports', PassportsPage::class)->name('passports');
Route::livewire('daily', Quiz::class)->name('quiz');
Route::livewire('places/{place}', PlaceShow::class)->name('places.show');
Route::livewire('authors/{author:slug}', AuthorShow::class)->name('authors.show');
Route::livewire('ask/{thread}', AmaShow::class)->name('ama.show');
Route::livewire('best-of/{ballot}', BallotShow::class)->name('ballots.show');

// Sponsored placements count a click, then send people on to the sponsor.
Route::get('s/{sponsorship}', function (Sponsorship $sponsorship) {
    Ads::click($sponsorship);

    return redirect()->away($sponsorship->url ?: ($sponsorship->place ? route('places.show', $sponsorship->place) : route('home')));
})->middleware('throttle:60,1')->name('sponsorships.click');
Route::get('stops/{code}', function (string $code) {
    abort_unless(config('stoop.stations.'.strtoupper($code)), 404);

    return redirect()->route('home', ['stop' => strtoupper($code)]);
})->name('stops.show');
Route::get('transparency', TransparencyController::class)->name('transparency');

// The weekly email's one-click unsubscribe link...
// GET only shows a button, because email scanners open links; POST (the button, or the
// mail app's one-click unsubscribe) does it.
Route::get('newsletter/unsubscribe/{user}', fn (User $user) => view('unsubscribe', ['done' => false, 'action' => request()->fullUrl()]))
    ->middleware('signed')->name('newsletter.unsubscribe');
Route::post('newsletter/unsubscribe/{user}', function (User $user) {
    $user->forceFill(['newsletter' => false])->save();

    return view('unsubscribe', ['done' => true, 'action' => null]);
})->middleware('signed')->withoutMiddleware(ValidateCsrfToken::class);

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

// Around town was split: city events are on Happenings, game days and contests in Play.
Route::redirect('around-town', '/play')->name('around-town');
Route::livewire('events/{event:slug}', EventShow::class)->name('events.show');
Route::livewire('contests/{contest:slug}', ContestShow::class)->name('contests.show');
Route::get('snow', fn () => view('snow', [
    'snowDay' => Settings::snowDay(),
    'reports' => Dispatch::with('user')->visibleTo(null)->where('type', 'snow')->whereNotNull('stop')
        ->where('created_at', '>=', now()->subDays(2))->latest()->get()->unique('stop')->sortByDesc(fn ($d) => $d->details['snow_inches'] ?? 0),
]))->name('snow');

Route::get('rivalries', fn () => view('rivalries', [
    'current' => Rivalries::matchups(Rivalries::weekStart()),
    'last' => Rivalries::matchups(Rivalries::weekStart()->subWeek()),
    'lines' => Rivalries::lineStandings(),
    'cup' => Rivalries::lineCup(Rivalries::monthStart()),
    'lastCup' => Rivalries::lineCup(Rivalries::monthStart()->subMonthNoOverflow()),
    'winners' => Rivalries::cupWinners(6),
]))->name('rivalries');

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
            abort_unless(auth()->user()->hasRole('editor'), 403);

            return new WeeklyDigest(Newsletter::digest(), null);
        })->name('.newsletter.preview');
        Route::livewire('audit', Admin\AuditLog::class)->name('.audit');
        Route::livewire('quiz', Admin\QuizQuestions::class)->name('.quiz');
        Route::livewire('moments', Admin\Moments::class)->name('.moments');
        Route::livewire('magazine', Admin\Magazine::class)->name('.magazine');
        Route::livewire('history', Admin\HistoryEntries::class)->name('.history');
        Route::livewire('moderation-insights', Admin\ModerationInsights::class)->name('.moderation-insights');
        Route::livewire('activity', Admin\Activity::class)->name('.activity');
        Route::livewire('spam', Admin\Spam::class)->name('.spam');
        Route::livewire('bots', Admin\Bots::class)->name('.bots');
        Route::livewire('roles', Admin\Roles::class)->name('.roles');
        Route::livewire('real-estate', Admin\RealEstate::class)->name('.real-estate');
        Route::livewire('insights', Admin\Insights::class)->name('.insights');
        Route::get('insights.csv', [InsightsController::class, 'csv'])->middleware('throttle:20,1')->name('.insights.csv');
        Route::get('insights/print', [InsightsController::class, 'print'])->middleware('throttle:20,1')->name('.insights.print');
        Route::livewire('editorial', Admin\Editorial::class)->name('.editorial');
        Route::livewire('anc', Admin\AncSummaries::class)->name('.anc');
        Route::get('ballots/{ballot}/results.csv', function (Ballot $ballot) {
            abort_unless(auth()->user()->hasRole('editor'), 403);
            Audit::log('ballot.exported', $ballot);

            return response($ballot->csv(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.str($ballot->title)->slug().'-results.csv"']);
        })->middleware('throttle:20,1')->name('.ballots.csv');
    });
    // A dispatch author answers an editor's request to quote it (from Notifications).
    Route::post('quote-requests/{quoteRequest}/{answer}', function (QuoteRequest $quoteRequest, string $answer) {
        QuoteRequests::decide(auth()->user(), $quoteRequest, $answer === 'allow');

        return back();
    })->whereIn('answer', ['allow', 'decline'])->middleware('throttle:20,1')->name('quote-requests.answer');
    Route::get('settings/export', ExportController::class)->middleware('throttle:5,1')->name('export');
    // Push notification sign-ups from a browser or the installed app...
    Route::post('push/subscriptions', function (Request $request) {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint' => $data['endpoint']],
            ['user_id' => $request->user()->id, 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'], 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->noContent();
    })->middleware('throttle:20,1')->name('push.subscribe');
    Route::delete('push/subscriptions', function (Request $request) {
        DB::table('push_subscriptions')->where('user_id', $request->user()->id)->where('endpoint', $request->input('endpoint'))->delete();

        return response()->noContent();
    })->name('push.unsubscribe');

    Route::livewire('messages', Inbox::class)->name('messages');
    Route::livewire('messages/{conversation}', ConversationView::class)->name('messages.show');
    Route::get('messages/images/{image}', function (MessageImage $image) {
        abort_unless($image->message->conversation->participants()->whereKey(auth()->id())->exists(), 404);

        return response()->file(Storage::disk('local')->path($image->path), ['Cache-Control' => 'private, max-age=86400']);
    })->name('messages.image');
    Route::get('banned', fn () => view('banned', ['ban' => auth()->user()->activeBan()]))->name('banned');
});

require __DIR__.'/settings.php';
require __DIR__.'/community.php';
require __DIR__.'/sports.php';
require __DIR__.'/web-newsroom.php';
require __DIR__.'/web-ads.php';
require __DIR__.'/web-challenges.php';
require __DIR__.'/web-business.php';
