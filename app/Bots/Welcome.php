<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Notifications\Welcome as WelcomeNotice;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Greets each new account once, with an in-app notification (never a post or a DM):
 * three tips for their home stops and three active neighbors to follow.
 */
class Welcome extends Bot
{
    public const GENERAL_TIPS = [
        'Ask DC (the yellow line) is where to ask neighbors anything.',
        'Tag a dispatch with its Metro stop and it shows up on that station’s page.',
        'Post from a stop a few times a month and you could become its mayor.',
    ];

    public const NO_STOPS_TIP = 'Add up to 3 home stops in your profile settings for tips closer to home.';

    public function username(): string
    {
        return 'welcome';
    }

    public function name(): string
    {
        return 'Welcome';
    }

    public function description(): string
    {
        return 'Sends each new neighbor one welcome note with local tips and people to follow.';
    }

    /**
     * Posting needs the house rules accepted first, so this sweep catches everyone who
     * accepted them, on the website or in the app.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->run())->name('bot:welcome')->everyFiveMinutes()->withoutOverlapping();
    }

    /**
     * A first post gets its welcome right away, without waiting for the sweep. Never replies.
     */
    public function wantsToReply(Dispatch $dispatch): bool
    {
        if ($dispatch->user->created_at?->gt(now()->subWeek())) {
            $this->welcome($dispatch->user);
        }

        return false;
    }

    /**
     * Welcome everyone who joined this week, accepted the rules and hasn't been welcomed.
     */
    public function run(): int
    {
        return User::where('is_bot', false)->where('username', '!=', config('stoop.magazine_account'))
            ->whereNotNull('accepted_rules_at')->where('created_at', '>=', now()->subWeek())
            ->whereNotIn('id', DB::table('bot_welcomes')->select('user_id'))
            ->get()->filter(fn (User $user) => $this->welcome($user))->count();
    }

    /**
     * Sends the welcome unless this person already got one. Returns whether it sent.
     */
    public function welcome(User $user): bool
    {
        if ($user->is_bot || ! $user->accepted_rules_at || $user->username === config('stoop.magazine_account')) {
            return false;
        }

        // The insert is the once-only guarantee: a second sweep or a racing first post inserts nothing.
        if (! DB::table('bot_welcomes')->insertOrIgnore(['user_id' => $user->id, 'created_at' => now()])) {
            return false;
        }

        $user->notify(new WelcomeNotice($this->account(), $this->tips($user), $this->people($user)));

        return true;
    }

    /**
     * Three tips: a place and the mayor for each home stop first, then the general ones.
     *
     * @return list<string>
     */
    public function tips(User $user): array
    {
        $local = collect($user->stops ?: [])->map(function (string $stop) {
            $name = Stations::name($stop);
            $place = Place::where('stop', $stop)->orderByRaw('list_rank is null')->orderBy('list_rank')->first();
            $mayor = Stations::mayorOf($stop);

            return array_filter([
                $place ? "Near $name, try {$place->name}." : null,
                $mayor ? "The mayor of $name is @{$mayor->username}." : "$name has no mayor yet. Post from there a few times this month to claim it.",
            ]);
        });

        // Round-robin across stops so each one gets a tip before any gets a second.
        $interleaved = collect([0, 1])->flatMap(fn ($i) => $local->pluck($i)->filter());

        return $interleaved->whenEmpty(fn ($tips) => $tips->push(self::NO_STOPS_TIP))->concat(self::GENERAL_TIPS)->take(3)->values()->all();
    }

    /**
     * Up to three of the most active people at the user's stops this month, then anywhere.
     *
     * @return list<string>
     */
    public function people(User $user): array
    {
        $skip = [$user->id, ...$user->hiddenUserIds()];
        $ranked = fn (?array $stops) => Stations::points(now()->subDays(30), null, $stops)
            ->groupBy('user_id')->map(fn ($rows) => $rows->sum('points'))->sortDesc()->keys();

        $ids = ($user->stops ? $ranked($user->stops) : collect())->concat($ranked(null))
            ->reject(fn ($id) => in_array($id, $skip, true))->unique()->values();

        $people = User::whereIn('id', $ids)->where('is_bot', false)->where('username', '!=', config('stoop.magazine_account'))->pluck('username', 'id');

        return $ids->filter(fn ($id) => isset($people[$id]))->take(3)->map(fn ($id) => $people[$id])->values()->all();
    }
}
