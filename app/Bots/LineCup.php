<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Rivalries;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * @linecup posts the month's Line Cup standings every Monday, with a bit of friendly trash
 * talk aimed at the lines (never at people), and the winner once each month ends.
 */
class LineCup extends Bot
{
    // :first and :last are line names. Lines only: nothing about riders.
    public const TAUNTS = [
        ':first Line is running on time for once. Enjoy it.',
        ':last Line, the doors are closing. Step it up.',
        ':first Line out front. :last Line still waiting on the platform.',
        'Is :last Line single-tracking again? Plenty of month left to catch up.',
        ':first Line riders are standing on the right and walking on the left to the top.',
        ':last Line, this is your train. Board it.',
    ];

    public function username(): string
    {
        return 'linecup';
    }

    public function name(): string
    {
        return 'Line Cup';
    }

    public function description(): string
    {
        return 'Line Cup standings every Monday morning, and the winning line when the month ends.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postStandings())->name('bot:linecup:standings')
            ->mondays()->at('9:00')->timezone(config('stoop.timezone'));
        $schedule->call(fn () => $this->postWinner())->name('bot:linecup:winner')
            ->monthlyOn(1, '10:00')->timezone(config('stoop.timezone'));
    }

    public function postStandings(): ?Dispatch
    {
        $month = Rivalries::monthStart();
        $all = Rivalries::lineCup($month);
        $cup = $all->filter(fn ($line) => $line['points'] > 0);

        if ($cup->isEmpty() || $this->posted('Line Cup standings', now(config('stoop.timezone'))->startOfDay())) {
            return null;
        }

        $standings = $cup->keys()->map(fn ($line, $i) => ($i + 1).'. '.self::label($line).', '.$cup[$line]['points'].' pts')->join("\n");
        $week = (int) now(config('stoop.timezone'))->weekOfYear;
        $taunt = strtr(self::TAUNTS[$week % count(self::TAUNTS)], [':first' => self::label($cup->keys()->first(), false), ':last' => self::label($all->keys()->last(), false)]);

        return $this->post(['line' => 'blue', 'body' => Str::limit('Line Cup standings for '.$month->format('F').":\n$standings\n\n$taunt ".route('line-cup'), config('stoop.max_length') - 1, '…')]);
    }

    /**
     * This month's standings so far.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        $month = Rivalries::monthStart();
        $cup = Rivalries::lineCup($month)->filter(fn ($line) => $line['points'] > 0);

        if ($cup->isEmpty()) {
            return 'No Line Cup points yet in '.$month->format('F').'. Posts tagged with a line’s stops, replies there and Best Answers all score for it. '.route('line-cup');
        }

        return 'Line Cup for '.$month->format('F').' so far: '.$cup->keys()->take(6)->map(fn ($line, $i) => ($i + 1).'. '.self::label($line).', '.$cup[$line]['points'].' pts')->join('; ').'. '.route('line-cup');
    }

    /**
     * The winner of the month that just ended, once. Ties and empty months get no post.
     */
    public function postWinner(): ?Dispatch
    {
        $month = Rivalries::monthStart()->subMonthNoOverflow();
        $line = Rivalries::cupWinner($month);

        if (! $line || $this->posted('The '.$month->format('F Y').' Line Cup', $month)) {
            return null;
        }

        $cup = Rivalries::lineCup($month)[$line];

        return $this->post(['line' => 'blue', 'body' => 'The '.$month->format('F Y').' Line Cup goes to the '.self::label($line).' with '.$cup['points'].' points from '
            .$cup['people'].' '.Str::plural('rider', $cup['people']).'. Its regulars get the crown. New month, clean slate: '.route('line-cup')]);
    }

    protected static function label(string $line, bool $suffix = true): string
    {
        return ucfirst($line).($suffix ? ' Line' : '');
    }

    protected function posted(string $prefix, CarbonInterface $since): bool
    {
        return $this->account()->dispatches()->withTrashed()->whereNull('parent_id')
            ->where('created_at', '>=', $since->utc())->where('body', 'like', $prefix.'%')->exists();
    }
}
