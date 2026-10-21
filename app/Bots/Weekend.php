<?php

namespace App\Bots;

use App\Models\CityEvent;
use App\Models\Dispatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * @weekend posts a Friday round-up of city events and Happening dispatches for Friday to Sunday,
 * with staff's editors' picks first.
 */
class Weekend extends Bot
{
    public const PREFIX = 'Weekend round-up';

    public function username(): string
    {
        return 'weekend';
    }

    public function name(): string
    {
        return 'Weekend';
    }

    public function description(): string
    {
        return 'A round-up of what’s on this weekend, every Friday at noon.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postRoundup())->name('bot:weekend')
            ->fridays()->at('12:00')->timezone(config('stoop.timezone'));
    }

    /**
     * Post this weekend's round-up, once. Skipped when there's nothing on.
     */
    public function postRoundup(): ?Dispatch
    {
        $tz = config('stoop.timezone');
        $now = CarbonImmutable::now($tz);
        $friday = $now->isFriday() ? $now->startOfDay() : $now->next(CarbonImmutable::FRIDAY);
        $monday = $friday->addDays(3);

        if ($this->account()->dispatches()->withTrashed()->where('created_at', '>=', $now->startOfDay()->utc())->where('body', 'like', self::PREFIX.'%')->exists()) {
            return null;
        }

        $events = CityEvent::where('starts_at', '<', $monday->utc())->where('ends_at', '>=', $friday->utc())->orderBy('starts_at')->take(4)->get();
        $happenings = Dispatch::visibleTo(null)->whereNull('parent_id')->where('type', 'happening')->whereNotNull('happening_at')
            ->where('created_at', '>=', $friday->subDays(7)->utc())->where('created_at', '<', $monday->utc())
            ->withCount(['markers as going_count' => fn ($q) => $q->where('marks.kind', 'going')])
            ->get();
        $picks = $happenings->filter(fn (Dispatch $d) => $d->weekend_picked_at && CarbonImmutable::parse($d->weekend_picked_at)->gte($friday->subDays(7)));
        $top = $happenings->diff($picks)->filter(fn (Dispatch $d) => self::isThisWeekend($d, $friday))->sortByDesc('going_count');

        if ($events->isEmpty() && $picks->isEmpty() && $top->isEmpty()) {
            return null;
        }

        $sections = [
            'Editors’ picks:' => $picks->map(fn ($d) => self::dispatchLine($d)),
            'City events:' => $events->map(fn (CityEvent $e) => '• '.$e->name.' ('.$e->starts_at->timezone($tz)->format('D g:ia').')'
                .(preg_match('/\bticket/i', (string) $e->description) ? ' · Ticketed' : '').' '.route('events.show', $e)),
            'From neighbors:' => $top->map(fn ($d) => self::dispatchLine($d)),
        ];

        // Fit as much as the 500-character limit allows, picks first.
        $body = self::PREFIX.', '.$friday->format('D M j').' to '.$friday->addDays(2)->format('D M j').':';
        foreach ($sections as $heading => $lines) {
            $added = false;
            foreach ($lines as $line) {
                $next = ($added ? '' : "\n\n".$heading)."\n".$line;
                if (mb_strlen($body.$next) <= config('stoop.max_length')) {
                    $body .= $next;
                    $added = true;
                }
            }
        }

        return $this->post(['line' => 'orange', 'body' => $body]);
    }

    /**
     * Happening times are free text ("Saturday · 2pm", "Tonight · 8pm"), read against when
     * the dispatch was posted.
     */
    public static function isThisWeekend(Dispatch $d, CarbonImmutable $friday): bool
    {
        $when = strtolower($d->happening_at);
        $posted = CarbonImmutable::parse($d->created_at)->timezone(config('stoop.timezone'))->startOfDay();
        $inWeekend = fn (CarbonImmutable $day) => $day->gte($friday) && $day->lt($friday->addDays(3));

        return match (true) {
            str_contains($when, 'tonight') || str_contains($when, 'today') => $inWeekend($posted),
            str_contains($when, 'tomorrow') => $inWeekend($posted->addDay()),
            // A weekday name posted since Monday means this coming one.
            (bool) preg_match('/\b(fri(day)?|sat(urday)?|sun(day)?|weekend)\b/', $when) => $posted->gte($friday->subDays(4)),
            default => false,
        };
    }

    /**
     * One line for a dispatch: a short excerpt with no mentions or links, its time and labels.
     */
    protected static function dispatchLine(Dispatch $d): string
    {
        $excerpt = Str::limit(trim(preg_replace(['~https?://\S+~i', '/@/', '/\s+/'], ['', '', ' '], $d->body)), 70);
        $labels = array_filter([
            $d->sponsor ? 'Sponsored by '.$d->sponsor : null,
            $d->as_business ? 'Posted by the venue' : null,
            preg_match('/\btickets?\b/i', $d->body) ? 'Ticketed' : null,
        ]);

        return '• '.$excerpt.' ('.str_replace('@', '', $d->happening_at).')'.($labels ? ' · '.implode(' · ', $labels) : '').' '.route('dispatches.show', $d);
    }
}
