<?php

namespace App\Bots;

use App\Support\HomeSales;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;

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
