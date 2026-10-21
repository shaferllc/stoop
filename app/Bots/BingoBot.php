<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\User;
use App\Support\Bingo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @bingo announces each week's Metro Bingo cards on Monday morning, posts how many people got
 * a bingo on Sunday evening, and tells you how your own card is going.
 */
class BingoBot extends Bot
{
    public function username(): string
    {
        return 'bingo';
    }

    public function name(): string
    {
        return 'Metro Bingo';
    }

    public function description(): string
    {
        return 'New Metro Bingo cards every Monday and the week’s bingos on Sunday. Reply “@bingo” to see your card.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postCards())->name('bot:bingo:cards')
            ->mondays()->at('8:00')->timezone(config('stoop.timezone'));
        $schedule->call(fn () => $this->postBoard())->name('bot:bingo:board')
            ->sundays()->at('18:00')->timezone(config('stoop.timezone'));
    }

    public function postCards(): ?Dispatch
    {
        $week = Bingo::weekStart();

        if ($this->postedThisWeek('New Metro Bingo cards')) {
            return null;
        }

        // Three squares from this week's mix, the same all week.
        $seed = $week->toDateString();
        $sample = collect(Bingo::SQUARES)->sortBy(fn ($square, $key) => crc32($seed.$key))->take(3)->map(fn ($square) => $square[0]);

        return $this->post(['line' => 'orange', 'body' => 'New Metro Bingo cards for the week of '.$week->format('M j').'. Everyone’s card is different. On some this week: '
            .$sample->join('; ').'. Five in a row wins. '.route('bingo')]);
    }

    /**
     * How many people got a bingo this week. There's no setting to show your name on a public
     * board yet, so it's counts only.
     */
    public function postBoard(): ?Dispatch
    {
        $week = Bingo::weekStart();

        if ($this->postedThisWeek('Bingo board')) {
            return null;
        }

        // Only people who played (marked a square by hand) can win, so they're the only ones to check.
        $players = User::whereIn('id', DB::table('bingo_marks')->where('week', $week->toDateString())->select('user_id'))->get();
        $winners = $players->filter(fn (User $user) => Bingo::card($user, $week)['lines'] > 0)->count();

        if ($players->isEmpty()) {
            return null;
        }

        return $this->post(['line' => 'orange', 'body' => 'Bingo board for the week of '.$week->format('M j').': '
            .($winners ? $winners.' '.Str::plural('neighbor', $winners).' got a bingo, out of '.$players->count().' playing.' : $players->count().' playing, and no bingos yet.')
            .' There’s still tonight to finish a row. New cards tomorrow at 8. '.route('bingo')]);
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $card = Bingo::card($dispatch->user);
        $marked = array_column($card['squares'], 'marked');
        $count = count(array_filter($marked));

        if ($card['lines'] > 0) {
            return "You’ve marked $count of 25 and you have ".($card['lines'] === 1 ? 'a bingo' : $card['lines'].' bingos').' this week. Nice. '.route('bingo');
        }

        // The first line that's one square away, and which square.
        foreach (self::lineNames() as $name => $cells) {
            $missing = array_values(array_filter($cells, fn ($i) => ! $marked[$i]));
            if (count($missing) === 1) {
                return "You’ve marked $count of 25; one away on $name: ".$card['squares'][$missing[0]]['label'].'. '.route('bingo');
            }
        }

        return "You’ve marked $count of 25 so far this week. Keep going: ".route('bingo');
    }

    /**
     * Every row, column and diagonal by name, with its square positions.
     *
     * @return array<string, array<int, int>>
     */
    public static function lineNames(): array
    {
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines['row '.($i + 1)] = range($i * 5, $i * 5 + 4);
        }
        for ($i = 0; $i < 5; $i++) {
            $lines['column '.($i + 1)] = range($i, 24, 5);
        }

        return $lines + ['the diagonal from the top left' => [0, 6, 12, 18, 24], 'the diagonal from the top right' => [4, 8, 12, 16, 20]];
    }

    protected function postedThisWeek(string $prefix): bool
    {
        return $this->account()->dispatches()->withTrashed()->whereNull('parent_id')
            ->where('created_at', '>=', Bingo::weekStart()->utc())->where('body', 'like', $prefix.'%')->exists();
    }
}
