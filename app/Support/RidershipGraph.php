<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A year of posting as a grid of days, like a Metro ridership chart. Darker days had more dispatches.
 */
class RidershipGraph
{
    public static function svg(User $user, string $theme = 'light'): string
    {
        $counts = Achievements::dailyCounts($user);
        $empty = $theme === 'dark' ? '#262a2c' : '#e8ebe8';
        $text = $theme === 'dark' ? '#9aa1a3' : '#5d6366';
        $shades = ['#f6b3bf', '#e8667f', '#c8102e', '#7d0a1d'];

        $tz = config('stoop.timezone');
        $end = now($tz)->startOfDay();
        $start = $end->subWeeks(52)->startOfWeek(CarbonInterface::SUNDAY);
        $size = 11;
        $gap = 3;

        $cells = '';
        for ($day = $start, $i = 0; $day->lte($end); $day = $day->addDay(), $i++) {
            $n = $counts[$day->toDateString()] ?? 0;
            $fill = $n === 0 ? $empty : $shades[min($n, 4) - 1];
            $x = intdiv($i, 7) * ($size + $gap);
            $y = ($i % 7) * ($size + $gap) + 16;
            $label = e($day->format('M j, Y').': '.$n.' '.str('dispatch')->plural($n));
            $cells .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$size}\" height=\"{$size}\" rx=\"2\" fill=\"{$fill}\"><title>{$label}</title></rect>";
        }

        $months = '';
        for ($m = $start->startOfMonth()->addMonth(); $m->lte($end); $m = $m->addMonth()) {
            $x = intdiv($start->diffInDays($m), 7) * ($size + $gap);
            $months .= "<text x=\"{$x}\" y=\"10\" font-size=\"9\" fill=\"{$text}\" font-family=\"system-ui, sans-serif\">{$m->format('M')}</text>";
        }

        $width = 53 * ($size + $gap);
        $height = 7 * ($size + $gap) + 16;

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\" width=\"{$width}\" height=\"{$height}\" role=\"img\" aria-label=\"".e($user->name)."’s dispatches over the past year\">{$months}{$cells}</svg>";
    }
}
