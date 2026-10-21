<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\HomeSales;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * @homes posts a weekly Real Estate roundup: the median sale price near the three busiest
 * stations over the last 30 days, from DC's public property records.
 */
class Homes extends Bot
{
    public function username(): string
    {
        return 'homes';
    }

    public function name(): string
    {
        return 'Stoop Homes';
    }

    public function description(): string
    {
        return 'Weekly home sale prices near Metro stations, from DC property records.';
    }

    /**
     * The median near a station the mention names, or this month's busiest stations.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        $ask = trim((string) preg_replace('/^.*?@homes\b/is', '', $dispatch->body));
        $stop = $ask !== '' ? Stations::find($ask) : null;

        if ($stop && ($stats = HomeSales::summary($stop))) {
            $last = $stats['recent']->first();

            return 'Homes within 1 km of '.Stations::name($stop).' sold for a median $'.number_format($stats['median']).' over the last 90 days ('
                .$stats['count'].' '.Str::plural('sale', $stats['count']).'). Latest: '.$last->block.', $'.number_format($last->price).'. '.route('stops.show', $stop);
        }

        $busiest = HomeSales::busiest(30, 1);
        $intro = $stop ? 'No recorded sales near '.Stations::name($stop).' in the last 90 days. ' : '';

        if ($busiest->isEmpty()) {
            return $intro.'No home sales recorded this month yet. Ask “@homes Navy Yard” for a station’s median.';
        }

        return $intro.'Most home sales this month: '.$busiest->keys()->map(fn ($code, $i) => ($i + 1).'. '.Stations::name($code).', '.$busiest[$code]['count'].' sold, median $'.number_format($busiest[$code]['median']))->join('; ')
            .'. Ask “@homes Navy Yard” for any station. '.route('stops.show', $busiest->keys()->first());
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->roundup())->weeklyOn(6, '9:00')->timezone(config('stoop.timezone'))->name('homes:roundup');
    }

    /**
     * One post per busy station (at most 3), skipped for any station already covered this week.
     * Returns how many went up.
     */
    public function roundup(): int
    {
        $posted = 0;

        foreach (HomeSales::busiest() as $stop => $stats) {
            if ($this->account()->dispatches()->where('stop', $stop)->where('created_at', '>=', now()->subDays(6))->exists()) {
                continue;
            }

            $this->post([
                'line' => 'green',
                'stop' => $stop,
                'body' => 'Homes near '.Stations::name($stop).' sold for a median $'.number_format($stats['median']).' this month ('
                    .$stats['count'].' sales within 1 km). Source: DC Office of Tax and Revenue property records.',
            ]);
            $posted++;
        }

        return $posted;
    }
}
