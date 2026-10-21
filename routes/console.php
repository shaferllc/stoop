<?php

use App\Actions\AnnounceDispatch;
use App\Actions\FileDispatch;
use App\Bots\Bot;
use App\Jobs\ModerateDispatch;
use App\Mail\WeeklyDigest;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ModerationMail;
use App\Support\Bots;
use App\Support\Civility;
use App\Support\HomeSales;
use App\Support\Later;
use App\Support\Magazine;
use App\Support\ModerationModel;
use App\Support\Newsletter;
use App\Support\OpenAiModeration;
use App\Support\SignupRisk;
use App\Support\SpamRings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stoop:publish-scheduled', function () {
    Dispatch::where('status', 'scheduled')->where('publish_at', '<=', now())->each(function (Dispatch $dispatch) {
        $pending = (ModerationModel::enabled() || OpenAiModeration::enabled()) && ! $dispatch->user->isHouseAccount();
        $dispatch->forceFill([
            'status' => Civility::shouldHold($dispatch->body) || SignupRisk::holds($dispatch->user) ? 'held' : ($pending ? 'pending' : 'approved'),
            'created_at' => $dispatch->publish_at,
        ])->save();
        $pending ? Later::run(new ModerateDispatch($dispatch)) : AnnounceDispatch::run($dispatch);
    });
})->purpose('Publish scheduled dispatches whose time has come');

Artisan::command('stoop:purge-deleted', function () {
    // After the undo window, erase deleted dispatches. Ones with replies keep an empty tombstone row.
    Dispatch::onlyTrashed()->where('deleted_at', '<', now()->subMinute())->where('body', '!=', '')
        ->each(function (Dispatch $dispatch) {
            $dispatch->images->each(fn ($image) => Storage::disk('public')->delete($image->path));
            $dispatch->images()->delete();

            Dispatch::withTrashed()->where('parent_id', $dispatch->id)->exists()
                ? $dispatch->forceFill(['body' => ''])->saveQuietly()
                : $dispatch->forceDelete();
        });
})->purpose('Erase deleted dispatches once the undo window has passed');

Artisan::command('stoop:import-articles', function () {
    $this->info('Posted '.Magazine::import().' new articles.');
})->purpose('Post new magazine articles from the site feed');

Artisan::command('stoop:backfill-authors', function () {
    $this->info('Credited writers on '.Magazine::backfillAuthors().' articles.');
})->purpose('Credit writers on articles imported before bylines were stored');

Artisan::command('stoop:import-home-sales', function () {
    $this->info('Stored '.HomeSales::import().' home sales near stations.');
})->purpose('Import recent DC home sales from the city\'s property records');

Artisan::command('stoop:send-newsletter {--to= : Send one copy to this email instead of every subscriber}', function () {
    $digest = Newsletter::digest();
    if ($to = $this->option('to')) {
        Mail::to($to)->send(new WeeklyDigest($digest, null));
        $this->info("Sent a test to $to.");

        return;
    }
    $sent = 0;
    User::where('newsletter', true)->whereNotNull('email_verified_at')->where('is_bot', false)->each(function ($user) use ($digest, &$sent) {
        Mail::to($user)->queue(new WeeklyDigest($digest, $user));
        $sent++;
    });
    $this->info("Queued $sent newsletters.");
})->purpose('Send the weekly Stoop newsletter');

Artisan::command('stoop:go-go-friday', function () {
    // The house account opens the weekly music thread, once per Friday.
    $dc = Bots::account('dc');
    if ($dc->dispatches()->where('created_at', '>=', now(config('stoop.timezone'))->startOfDay()->utc())->where('body', 'like', 'It’s Go-Go Friday%')->exists()) {
        return;
    }
    FileDispatch::handle($dc, [
        'line' => 'blue',
        'body' => 'It’s Go-Go Friday. What are you playing today? Drop the song, the band, or where to catch a pocket this weekend.',
    ], confirmed: true);
    $this->info('Posted.');
})->purpose('Post the weekly Go-Go Friday music thread');

Artisan::command('stoop:detect-spam', function () {
    $this->info(SpamRings::detect().' open spam clusters.');
})->purpose('Look for spam rings: copied posts, new-account bursts, link spam and message blasts');

Artisan::command('stoop:admin-digest', function () {
    // Anything staff haven't touched in a day, emailed to admins. Nothing waiting, no email.
    $day = now()->subDay();
    $waiting = array_filter([
        'posts in the moderation queue' => Dispatch::where(fn ($q) => $q->whereIn('status', ['held', 'hidden'])->orWhereNotNull('flagged_at'))->where('status', '!=', 'removed')->where('updated_at', '<', $day)->count(),
        'reported posts' => Report::whereNull('resolved_at')->where('created_at', '<', $day)->distinct()->count('dispatch_id'),
        'appeals' => Appeal::where('status', 'open')->where('created_at', '<', $day)->count(),
        'reported messages' => MessageReport::whereNull('resolved_at')->where('created_at', '<', $day)->distinct()->count('message_id'),
    ]);
    if (! $waiting) {
        return;
    }
    $lines = ['These have been waiting more than 24 hours:', ...array_map(fn ($label, $n) => "- $n $label", array_keys($waiting), $waiting)];
    $admins = User::where('is_staff', true)->get()->filter(fn (User $u) => $u->hasRole('admin') && ! $u->isHouseAccount());
    Notification::send($admins, new ModerationMail('Stoop: '.array_sum($waiting).' items waiting over a day', $lines, route('moderation'), 'Open moderation'));
    $this->info('Sent to '.$admins->count().' admins.');
})->purpose('Email admins when moderation items have waited more than a day');

Schedule::command('stoop:detect-spam')->everyTenMinutes()->withoutOverlapping();
Schedule::command('stoop:admin-digest')->dailyAt('9:00')->timezone('America/New_York');
Schedule::command('stoop:go-go-friday')->fridays()->at('10:00')->timezone('America/New_York');
Schedule::command('stoop:import-articles')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('stoop:import-home-sales')->dailyAt('3:30')->timezone('America/New_York')->withoutOverlapping();
Schedule::command('stoop:send-newsletter')->weeklyOn(1, '8:00')->timezone('America/New_York');
Schedule::command('stoop:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('stoop:purge-deleted')->everyMinute()->withoutOverlapping();

// Lets the admin app tell whether the scheduler is running.
Schedule::call(fn () => Cache::forever('stoop:schedule-ran', now()->toIso8601String()))->everyMinute()->name('heartbeat');

// Each house bot in app/Bots registers its own scheduled posts.
foreach (Bot::all() as $bot) {
    $bot->schedule(app(Illuminate\Console\Scheduling\Schedule::class));
}
