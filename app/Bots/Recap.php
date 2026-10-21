<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Mentions;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sunday mornings: "Your stop this week" for the 5 busiest stations, with each one's three
 * best dispatches and its mayor. Only approved posts ever make it in.
 */
class Recap extends Bot
{
    public function username(): string
    {
        return 'recap';
    }

    public function name(): string
    {
        return 'Stop Recap';
    }

    public function description(): string
    {
        return 'Every Sunday morning, the week’s best dispatches at the five busiest stops.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->run())->name('bot:recap')->sundays()->at('9:00')->timezone(config('stoop.timezone'));
    }

    /**
     * Post the recaps. Safe to run twice: a stop already recapped today is skipped. Returns how many posted.
     */
    public function run(): int
    {
        $bot = $this->account();
        $week = fn () => Dispatch::topLevel()->visibleTo(null)
            ->where('created_at', '>=', now()->subWeek())
            ->whereHas('user', fn ($q) => $q->where('is_bot', false));

        $busiest = $week()->whereNotNull('stop')->groupBy('stop')
            ->selectRaw('stop, count(*) as posts')->orderByDesc('posts')->orderBy('stop')->limit(5)->pluck('stop');

        $posted = 0;
        foreach ($busiest as $stop) {
            if ($bot->dispatches()->withTrashed()->where('stop', $stop)->where('created_at', '>=', now(config('stoop.timezone'))->startOfDay()->utc())->exists()) {
                continue;
            }

            // Most replies plus half-smokes, ties to the newer post.
            $best = $week()->where('stop', $stop)->forCards()->latest()->get()
                ->sortByDesc(fn (Dispatch $d) => $d->replies_count + $d->half_smokes_count)->take(3)->values();

            $this->post(['line' => $best->first()->line, 'stop' => $stop, 'body' => $this->body($stop, $best)]);
            $posted++;
        }

        return $posted;
    }

    /**
     * @param  Collection<int, Dispatch>  $best
     */
    protected function body(string $stop, Collection $best): string
    {
        $mayor = Stations::mayorOf($stop);
        $head = 'Your stop this week: '.Stations::name($stop).($mayor ? ' · Mayor @'.$mayor->username : '');

        // Excerpts lose their mentions and links so the recap doesn't ping anyone or add a link card.
        $room = intdiv(config('stoop.max_length') - mb_strlen($head) - 2, max(1, $best->count())) - 60;
        $lines = $best->map(fn (Dispatch $d, $i) => ($i + 1).'. '.Str::limit(trim(preg_replace(['/\s+/', Mentions::URL_PATTERN, Mentions::PATTERN], [' ', '', '$1'], $d->body)), max(20, $room)).' '.route('dispatches.show', $d));

        return Str::limit($head."\n\n".$lines->join("\n"), config('stoop.max_length') - 1, '…');
    }
}
