<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Streaks with a "Single-Tracking" pass: one missed day per week (Monday to Sunday) is
 * forgiven automatically, so a long streak survives one off day.
 */
class Streaks
{
    /**
     * @param  array<int, string>  $days  Active days as Y-m-d, in DC time
     * @return array{current: int, longest: int, pass_used_this_week: bool}
     */
    public static function compute(array $days, ?CarbonImmutable $today = null): array
    {
        $today ??= now(config('stoop.timezone'))->toImmutable()->startOfDay();
        $active = array_flip($days);

        // Current streak: walk back from today (or yesterday, if today isn't done yet).
        $cursor = isset($active[$today->toDateString()]) ? $today : $today->subDay();
        $current = 0;
        $passes = [];
        while (true) {
            if (isset($active[$cursor->toDateString()])) {
                $current++;
            } elseif ($current > 0 && ! isset($passes[$cursor->format('o-W')]) && isset($active[$cursor->subDay()->toDateString()])) {
                $passes[$cursor->format('o-W')] = true;
            } else {
                break;
            }
            $cursor = $cursor->subDay();
        }

        // Longest streak: scan forward from the first active day.
        $longest = 0;
        if ($days) {
            sort($days);
            $run = 0;
            $used = [];
            for ($day = CarbonImmutable::parse($days[0]); $day->lte(CarbonImmutable::parse(end($days))); $day = $day->addDay()) {
                if (isset($active[$day->toDateString()])) {
                    $run++;
                    $longest = max($longest, $run);
                } elseif ($run > 0 && ! isset($used[$day->format('o-W')]) && isset($active[$day->addDay()->toDateString()])) {
                    $used[$day->format('o-W')] = true;
                } else {
                    $run = 0;
                    $used = [];
                }
            }
        }

        return [
            'current' => $current,
            'longest' => max($longest, $current),
            'pass_used_this_week' => isset($passes[$today->format('o-W')]),
        ];
    }
}
