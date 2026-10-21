<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\User;
use App\Support\DailyQuiz;
use App\Support\Profanity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @quizmaster announces The Daily each morning, posts the streak leaders at 8pm, and
 * congratulates people who share their result.
 */
class Quizmaster extends Bot
{
    // A shared result, as DailyQuiz::shareText writes it: "Stoop Daily #293 4/5".
    protected const SHARED = '/Stoop Daily #(\d+) (\d)\/(\d)/';

    public function username(): string
    {
        return 'quizmaster';
    }

    public function name(): string
    {
        return 'Quizmaster';
    }

    public function description(): string
    {
        return 'The Daily every morning, streak leaders at 8pm, and a cheer when you share your score.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postDaily())->name('bot:quizmaster:daily')
            ->dailyAt('7:00')->timezone(config('stoop.timezone'));
        $schedule->call(fn () => $this->postLeaders())->name('bot:quizmaster:leaders')
            ->dailyAt('20:00')->timezone(config('stoop.timezone'));
    }

    public function postDaily(): ?Dispatch
    {
        $today = DailyQuiz::today();

        if (! DailyQuiz::questionIdsFor($today) || $this->postedToday('The Daily #')) {
            return null;
        }

        return $this->post(['line' => 'blue', 'body' => 'The Daily #'.DailyQuiz::number($today).' is up: '.DailyQuiz::PER_DAY.' questions about DC, the same for everyone. Keep your streak going. '.route('quiz')]);
    }

    /**
     * The five longest current streaks. Everyone who plays is counted; there's no
     * public-stats setting to opt out of yet.
     */
    public function postLeaders(): ?Dispatch
    {
        $today = DailyQuiz::today();

        if ($this->postedToday('Streak leaders')) {
            return null;
        }

        // Only people who finished today or yesterday can have a current streak.
        // ponytail: one streak query per recent player; precompute streaks if that list grows into the thousands.
        $leaders = User::where('is_bot', false)
            ->whereIn('id', DB::table('quiz_attempts')->whereNotNull('completed_at')->where('day', '>=', $today->subDay()->toDateString())->select('user_id'))
            ->get()
            ->map(fn (User $user) => [$user, DailyQuiz::streaks($user)['current']])
            ->filter(fn ($row) => $row[1] >= 2 && ! Profanity::contains($row[0]->username))
            ->sortByDesc(fn ($row) => $row[1])->take(5)->values();

        if ($leaders->isEmpty()) {
            return null;
        }

        $body = 'Streak leaders after The Daily #'.DailyQuiz::number($today).':';
        foreach ($leaders as $i => [$user, $days]) {
            // Usernames without the @, so the list doesn't ping people every night.
            $body .= "\n".($i + 1).'. '.$user->username.', '.$days.' days';
        }

        return $this->post(['line' => 'blue', 'body' => $body."\nPlay today’s: ".route('quiz')]);
    }

    public function wantsToReply(Dispatch $dispatch): bool
    {
        return parent::wantsToReply($dispatch) || preg_match(self::SHARED, $dispatch->body);
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $today = DailyQuiz::today();

        if (! preg_match(self::SHARED, $dispatch->body, $m)) {
            $played = DB::table('quiz_attempts')->where(['user_id' => $dispatch->user_id, 'day' => $today->toDateString()])->whereNotNull('completed_at')->value('score');
            $streaks = DailyQuiz::streaks($dispatch->user);

            return 'The Daily #'.DailyQuiz::number($today).': '
                .($played !== null ? "you played today and got $played/".DailyQuiz::PER_DAY.'. ' : 'you haven’t played today yet. ')
                .($streaks['current'] > 0 ? "Your streak: {$streaks['current']} ".Str::plural('day', $streaks['current']).' (longest '.$streaks['longest'].'). ' : 'No streak going yet; today’s a good day to start one. ')
                .route('quiz');
        }

        // Only real, finished results, and one cheer per person per quiz.
        $number = (int) $m[1];
        $day = $today->subDays(DailyQuiz::number($today) - $number);
        $finished = DB::table('quiz_attempts')->where(['user_id' => $dispatch->user_id, 'day' => $day->toDateString()])->whereNotNull('completed_at')->exists();
        $cheered = $this->account()->dispatches()->withTrashed()->where('body', 'like', "The Daily #$number:%")
            ->whereHas('parent', fn ($q) => $q->where('user_id', $dispatch->user_id))->exists();

        if (! $finished || $cheered) {
            return null;
        }

        $streak = DailyQuiz::streaks($dispatch->user)['current'];

        return "The Daily #$number: {$m[2]}/{$m[3]}. ".((int) $m[2] === (int) $m[3] ? 'A perfect round. ' : 'Nice work. ')
            .($streak > 1 ? "That’s a $streak-day streak." : 'That’s day one of a streak. See you tomorrow.');
    }

    protected function postedToday(string $prefix): bool
    {
        // Scheduled posts are top-level; cheers are replies and don't count.
        return $this->account()->dispatches()->withTrashed()->whereNull('parent_id')
            ->where('created_at', '>=', DailyQuiz::today()->utc())->where('body', 'like', $prefix.'%')->exists();
    }
}
