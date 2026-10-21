<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Happening;
use App\Models\Place;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Happenings calendar, RSVPs (Going and Interested marks), tickets and .ics files.
 * Only happenings posted with a start time are on the calendar; the free-text "when"
 * ("Tonight · 8pm") can't be placed on a day.
 */
class Happenings
{
    /** Calendar entries last this long, since posts only have a start time. */
    public const HOURS = 2;

    /**
     * Verified place owners and staff can sell tickets (through an outside link).
     */
    public static function canTicket(User $user): bool
    {
        return $user->is_staff || Place::where('owner_id', $user->id)->exists();
    }

    /**
     * The host (or staff) sets the calendar start time (DC time) and, for place owners and
     * staff, a ticket price in dollars and an https ticket link.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function saveDetails(User $user, Dispatch $dispatch, array $input): Happening
    {
        abort_unless($dispatch->type === 'happening' && ! $dispatch->parent_id && ($user->id === $dispatch->user_id || $user->is_staff), 403);

        $data = Validator::make($input, [
            'starts_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'between:0,10000'],
            'ticket_url' => ['nullable', 'url:https', 'max:500'],
        ], [], ['ticket_url' => 'ticket link', 'starts_at' => 'start time'])->validate();

        if (! self::canTicket($user) && (($data['price'] ?? null) !== null || ($data['ticket_url'] ?? null))) {
            throw ValidationException::withMessages(['ticket_url' => 'Tickets are for verified place owners and staff.']);
        }

        $happening = $dispatch->happening()->updateOrCreate([], [
            'starts_at' => ($data['starts_at'] ?? null) ? CarbonImmutable::parse($data['starts_at'], config('stoop.timezone'))->utc() : null,
            ...(self::canTicket($user) ? [
                'price_cents' => ($data['price'] ?? null) !== null ? (int) round((float) $data['price'] * 100) : null,
                'ticket_url' => ($data['ticket_url'] ?? null) ?: null,
            ] : []),
        ]);

        if ($user->id !== $dispatch->user_id) {
            Audit::log('happening.updated', $dispatch);
        }

        return $happening;
    }

    /**
     * Featured happenings sit at the top of their day on the calendar. Staff only (it's paid placement).
     */
    public static function feature(User $user, Dispatch $dispatch, bool $featured): Happening
    {
        abort_unless($user->is_staff && $dispatch->type === 'happening' && ! $dispatch->parent_id, 403);

        $happening = $dispatch->happening()->firstOrCreate([]);
        $happening->update(['featured' => $featured]);
        Audit::log($featured ? 'happening.featured' : 'happening.unfeatured', $dispatch);

        return $happening;
    }

    /**
     * The app's view of a happening's calendar, RSVP and ticket details.
     *
     * @return array<string, mixed>
     */
    public static function present(Dispatch $dispatch): array
    {
        $h = $dispatch->happening;

        return [
            'when' => $dispatch->happening_at,
            'starts_at' => $h?->starts_at?->toIso8601String(),
            'featured' => (bool) $h?->featured,
            'ticketed' => (bool) $h?->isTicketed(),
            'price' => $h?->priceLabel(),
            'tickets_url' => $h?->isTicketed() ? route('happenings.tickets', $dispatch) : null,
            'ics_url' => $h?->starts_at ? route('happenings.ics', $dispatch) : null,
            ...self::counts($dispatch),
        ];
    }

    /**
     * Happenings on the calendar between two times, by start time.
     *
     * @return Builder<Dispatch>
     */
    public static function between(\DateTimeInterface $from, \DateTimeInterface $to, ?User $viewer = null, ?string $line = null, ?string $stop = null): Builder
    {
        return Dispatch::topLevel()->visibleTo($viewer)->where('type', 'happening')
            ->join('happenings', 'happenings.dispatch_id', '=', 'dispatches.id')
            ->whereBetween('happenings.starts_at', [$from, $to])
            ->when($line, fn ($q) => $q->where('dispatches.line', $line))
            ->when($stop, fn ($q) => $q->where('dispatches.stop', $stop))
            ->orderBy('happenings.starts_at')->orderByDesc('happenings.featured')
            ->select('dispatches.*')->with('happening');
    }

    /**
     * Calendar days (DC time) from a start, for a week or a month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $view, int $offset = 0): array
    {
        $today = now(config('stoop.timezone'))->toImmutable()->startOfDay();
        $from = $view === 'month' ? $today->startOfMonth()->addMonthsNoOverflow($offset) : $today->addWeeks($offset);

        return [$from, $view === 'month' ? $from->endOfMonth() : $from->addDays(7)->subSecond()];
    }

    /**
     * Who's going and who's interested, without the host (posting a Happening marks you Going).
     *
     * @return array{going: Collection<int, User>, interested: Collection<int, User>}
     */
    public static function rsvps(Dispatch $dispatch): array
    {
        $rows = DB::table('marks')->where('dispatch_id', $dispatch->id)->whereIn('kind', ['going', 'interested'])
            ->where('user_id', '!=', $dispatch->user_id)->orderBy('created_at')->get(['user_id', 'kind']);
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'username'])->keyBy('id');

        return [
            'going' => $rows->where('kind', 'going')->map(fn ($r) => $users[$r->user_id] ?? null)->filter()->values(),
            'interested' => $rows->where('kind', 'interested')->map(fn ($r) => $users[$r->user_id] ?? null)->filter()->values(),
        ];
    }

    /**
     * @return array{going: int, interested: int}
     */
    public static function counts(Dispatch $dispatch): array
    {
        $counts = DB::table('marks')->where('dispatch_id', $dispatch->id)->whereIn('kind', ['going', 'interested'])
            ->groupBy('kind')->selectRaw('kind, count(*) as c')->pluck('c', 'kind');

        return ['going' => (int) ($counts['going'] ?? 0), 'interested' => (int) ($counts['interested'] ?? 0)];
    }

    /**
     * The secret calendar feed token, made on first use. Resetting breaks the old link.
     */
    public static function feedToken(User $user, bool $reset = false): string
    {
        $token = DB::table('calendar_feeds')->where('user_id', $user->id)->value('token');

        if (! $token || $reset) {
            $token = Str::random(40);
            DB::table('calendar_feeds')->updateOrInsert(['user_id' => $user->id], ['token' => $token, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $token;
    }

    /**
     * Happenings someone hosts or RSVP'd to, for their calendar feed.
     *
     * @return Collection<int, Dispatch>
     */
    public static function forFeed(User $user): Collection
    {
        $mine = DB::table('marks')->where('user_id', $user->id)->whereIn('kind', ['going', 'interested'])->select('dispatch_id');

        return self::between(now()->subMonth(), now()->addYear())
            ->where(fn ($q) => $q->where('dispatches.user_id', $user->id)->orWhereIn('dispatches.id', $mine))
            ->with('user')->get();
    }

    /**
     * An iCalendar file (RFC 5545) for one or more happenings.
     *
     * @param  Collection<int, Dispatch>  $dispatches
     */
    public static function ics(Collection $dispatches, string $name): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Stoop//Happenings//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.self::escape($name)];

        foreach ($dispatches as $d) {
            $start = $d->happening->starts_at->utc();
            $lines = [...$lines,
                'BEGIN:VEVENT',
                'UID:happening-'.$d->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
                'DTSTAMP:'.$d->created_at->utc()->format('Ymd\THis\Z'),
                'DTSTART:'.$start->format('Ymd\THis\Z'),
                'DTEND:'.$start->copy()->addHours(self::HOURS)->format('Ymd\THis\Z'),
                'SUMMARY:'.self::escape(Str::limit(Str::before($d->body, "\n"), 80)),
                'DESCRIPTION:'.self::escape($d->body."\n\n".route('dispatches.show', $d)),
                'URL:'.route('dispatches.show', $d),
                ...($d->stop ? ['LOCATION:'.self::escape(Stations::name($d->stop).' Metro')] : []),
                'END:VEVENT',
            ];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    protected static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /**
     * Lines longer than 75 octets continue on the next line after a space.
     */
    protected static function fold(string $line): string
    {
        $out = mb_strcut($line, 0, 75);
        $line = substr($line, strlen($out));
        while ($line !== '') {
            $cut = mb_strcut($line, 0, 74);
            $out .= "\r\n ".$cut;
            $line = substr($line, strlen($cut));
        }

        return $out;
    }
}
