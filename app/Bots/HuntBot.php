<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\Hunt;
use App\Models\User;
use App\Support\Profanity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @hunt posts a scavenger hunt's first clue when it opens, a clue reminder each morning while
 * one runs, and a shout-out to the first finisher. It only ever says clue text: never a
 * step's stop, place or code word, and its posts are never tagged with a stop.
 */
class HuntBot extends Bot
{
    public function username(): string
    {
        return 'hunt';
    }

    public function name(): string
    {
        return 'Scavenger Hunts';
    }

    public function description(): string
    {
        return 'Clues for Stoop’s scavenger hunts. Reply “@hunt” for your next clue.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postOpenings() + $this->postFinishers())->name('bot:hunt:check')
            ->everyFifteenMinutes()->withoutOverlapping();
        $schedule->call(fn () => $this->postReminder())->name('bot:hunt:reminder')
            ->dailyAt('10:00')->timezone(config('stoop.timezone'));
    }

    /**
     * The first clue of each hunt that has opened, once per hunt. Returns how many posted.
     */
    public function postOpenings(): int
    {
        $posted = 0;
        foreach (Hunt::running()->with('steps')->get() as $hunt) {
            if ($hunt->steps->isEmpty() || $this->said('The '.$hunt->name.' hunt is open', $hunt)) {
                continue;
            }

            $this->post(['line' => 'orange', 'body' => $this->limit('The '.$hunt->name.' hunt is open until '.$this->ends($hunt).'. '
                .$hunt->steps->count().' '.Str::plural('clue', $hunt->steps->count()).'. The first: '.$hunt->steps->first()->clue, route('hunts.show', $hunt))]);
            $posted++;
        }

        return $posted;
    }

    /**
     * One morning post for every hunt that's running, skipping hunts that opened in the last day.
     */
    public function postReminder(): ?Dispatch
    {
        $hunts = Hunt::running()->with('steps')->where('starts_at', '<=', now()->subDay())->get()->filter(fn (Hunt $h) => $h->steps->isNotEmpty());

        if ($hunts->isEmpty() || $this->account()->dispatches()->withTrashed()->whereNull('parent_id')
            ->where('created_at', '>=', now(config('stoop.timezone'))->startOfDay()->utc())->where('body', 'like', 'Still hunting?%')->exists()) {
            return null;
        }

        $body = 'Still hunting? '.$hunts->map(fn (Hunt $h) => $h->name.' runs until '.$this->ends($h).'. Clue 1: '.$h->steps->first()->clue.' '.route('hunts.show', $h))->join("\n");

        return $this->post(['line' => 'orange', 'body' => $this->limit($body.' Reply “@hunt” for your next clue.')]);
    }

    /**
     * A shout-out to whoever found every step first, once per hunt. Returns how many posted.
     */
    public function postFinishers(): int
    {
        $posted = 0;
        foreach (Hunt::where('starts_at', '<=', now())->where('ends_at', '>=', now()->subDay())->withCount('steps')->get() as $hunt) {
            $first = $hunt->steps_count ? $hunt->leaderboard(1)->first() : null;

            if (! $first || $first->steps < $hunt->steps_count || Profanity::contains($first->user->username)
                || $this->said('First to finish', $hunt)) {
                continue;
            }

            // Username without the @, so the shout-out doesn't ping them.
            $this->post(['line' => 'orange', 'body' => $this->limit('First to finish '.$hunt->name.': '.$first->user->username.', who found all '
                .$hunt->steps_count.'. The hunt runs until '.$this->ends($hunt).', so there’s still time.', route('hunts.show', $hunt))]);
            $posted++;
        }

        return $posted;
    }

    /**
     * The next clue in each running hunt for the person who asked. Clue text only.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        $hunts = Hunt::running()->with('steps')->get()->filter(fn (Hunt $h) => $h->steps->isNotEmpty());

        if ($hunts->isEmpty()) {
            return 'No hunt is running right now. Past and upcoming ones: '.route('hunts');
        }

        $done = $this->doneStepIds($dispatch->user);
        $lines = $hunts->map(function (Hunt $hunt) use ($done) {
            $next = $hunt->steps->first(fn ($step) => ! in_array($step->id, $done, true));

            return $next
                ? $hunt->name.', clue '.$next->position.' of '.$hunt->steps->count().': '.$next->clue
                : $hunt->name.': you found them all. Nice work.';
        });

        return $this->limit($lines->join("\n"), $hunts->count() === 1 ? route('hunts.show', $hunts->first()) : route('hunts'));
    }

    /**
     * @return array<int, int>
     */
    protected function doneStepIds(User $user): array
    {
        return DB::table('hunt_completions')->where('user_id', $user->id)->pluck('hunt_step_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether this bot already posted something starting with $prefix about this hunt. */
    protected function said(string $prefix, Hunt $hunt): bool
    {
        return $this->account()->dispatches()->withTrashed()->whereNull('parent_id')
            ->where('body', 'like', $prefix.'%')->where('body', 'like', '%'.route('hunts.show', $hunt).'%')->exists();
    }

    protected function ends(Hunt $hunt): string
    {
        return $hunt->ends_at->timezone(config('stoop.timezone'))->format('M j');
    }

    /** Fits the text into a post, keeping the link whole (the once-only checks look for it). */
    protected function limit(string $text, string $url = ''): string
    {
        return trim(Str::limit($text, config('stoop.max_length') - 2 - mb_strlen($url), '…').' '.$url);
    }
}
