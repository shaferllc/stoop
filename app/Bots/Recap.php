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

        $busiest = $this->week(now()->subWeek())->whereNotNull('stop')->groupBy('stop')
            ->selectRaw('stop, count(*) as posts')->orderByDesc('posts')->orderBy('stop')->limit(5)->pluck('stop');

        $posted = 0;
        foreach ($busiest as $stop) {
            if ($bot->dispatches()->withTrashed()->where('stop', $stop)->where('created_at', '>=', now(config('stoop.timezone'))->startOfDay()->utc())->exists()) {
                continue;
            }

            $best = $this->best(now()->subWeek(), $stop);
            $this->post(['line' => $best->first()->line, 'stop' => $stop, 'body' => $this->body('Your stop this week: '.Stations::name($stop), $stop, $best)]);
            $posted++;
        }

        return $posted;
    }

    /**
     * This week's top 3 so far (since Monday, DC time), at a station the mention names or overall.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        $ask = trim((string) preg_replace('/^.*?@recap\b/is', '', $dispatch->body));
        $stop = $ask !== '' ? Stations::find($ask) : null;
        $best = $this->best(now(config('stoop.timezone'))->startOfWeek()->utc(), $stop, except: $dispatch->id);

        if ($best->isEmpty()) {
            return 'Nothing '.($stop ? 'tagged '.Stations::name($stop).' ' : '').'has made the week’s best yet. See what’s coming up: '.route('happenings');
        }

        return $this->body($stop ? 'Top at '.Stations::name($stop).' this week so far' : 'This week’s top dispatches so far', $stop, $best);
    }

    /**
     * Most replies plus half-smokes, ties to the newer post.
     *
     * @return Collection<int, Dispatch>
     */
    protected function best(\DateTimeInterface $since, ?string $stop = null, ?int $except = null): Collection
    {
        return $this->week($since)->when($stop, fn ($q) => $q->where('stop', $stop))->when($except, fn ($q) => $q->whereKeyNot($except))->forCards()->latest()->limit(200)->get()
            ->sortByDesc(fn (Dispatch $d) => $d->replies_count + $d->half_smokes_count)->take(3)->values();
    }

    protected function week(\DateTimeInterface $since)
    {
        return Dispatch::topLevel()->visibleTo(null)->where('created_at', '>=', $since)
            ->whereHas('user', fn ($q) => $q->where('is_bot', false));
    }

    /**
     * @param  Collection<int, Dispatch>  $best
     */
    protected function body(string $title, ?string $stop, Collection $best): string
    {
        // The mayor's name without the @, so the recap doesn't ping them every week.
        $mayor = $stop ? Stations::mayorOf($stop) : null;
        $head = $title.($mayor ? ' · Mayor '.$mayor->username : '');

        // Excerpts lose their mentions and links so the recap doesn't ping anyone or add a link card.
        $room = intdiv(config('stoop.max_length') - mb_strlen($head) - 2, max(1, $best->count())) - 60;
        $lines = $best->map(fn (Dispatch $d, $i) => ($i + 1).'. '.Str::limit(trim(preg_replace(['/\s+/', Mentions::URL_PATTERN, Mentions::PATTERN], [' ', '', '$1'], $d->body)), max(20, $room)).' '.route('dispatches.show', $d));

        return Str::limit($head."\n\n".$lines->join("\n"), config('stoop.max_length') - 1, '…');
    }
}
