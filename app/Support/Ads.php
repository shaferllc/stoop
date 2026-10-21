<?php

namespace App\Support;

use App\Models\BallotPromotion;
use App\Models\Dispatch;
use App\Models\Happening;
use App\Models\MeetupSponsor;
use App\Models\Presenter;
use App\Models\SponsoredDispatch;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

/**
 * Native ads: choosing a card for a feed slot, counting impressions once per viewer per day,
 * the daily frequency cap, clicks, "Hide this ad" and reports, and the sales reports.
 * Targeting is only by where the feed is (a stop or a topic), never by who's reading.
 */
class Ads
{
    /** Ads that count impressions and clicks, by the short name used in routes and tables. */
    public const KINDS = ['sponsorship' => Sponsorship::class, 'dispatch' => SponsoredDispatch::class, 'party' => BallotPromotion::class, 'presenter' => Presenter::class, 'meetup' => MeetupSponsor::class];

    /** Feeds get at most one ad per this many dispatches. */
    public const EVERY = 10;

    /** How often one person sees the same party promotion in a day. */
    public const PARTY_DAILY_CAP = 3;

    public const REPORT_REASONS = ['misleading' => 'Misleading', 'offensive' => 'Offensive', 'scam' => 'Looks like a scam', 'other' => 'Something else'];

    public static function find(string $kind, int $id): ?Model
    {
        return isset(self::KINDS[$kind]) ? self::KINDS[$kind]::find($id) : null;
    }

    public static function kindOf(Model $ad): string
    {
        return array_search($ad::class, self::KINDS, true);
    }

    /**
     * A keyed hash of the account (or, for guests, the session) and the day. No cookie of its
     * own, and the same person gets a different hash tomorrow.
     */
    public static function viewer(): string
    {
        $who = Auth::id() ? 'u'.Auth::id() : 's'.session()->getId();

        return hash_hmac('sha256', $who.'|'.self::today(), (string) config('app.key'));
    }

    /**
     * Pick a sponsored dispatch or party promotion for a feed slot and count it, or null.
     * $exclude holds "kind:id" keys already on the page.
     *
     * @param  array<int, string>  $stops  The feed's stop (or the Near tab's stops).
     * @param  array<int, string>  $exclude
     */
    public static function pick(array $stops, ?string $line, array $exclude = []): ?Model
    {
        $shows = DB::table('ad_views')->where('viewer', self::viewer())->where('day', self::today())
            ->get()->mapWithKeys(fn ($r) => [$r->kind.':'.$r->ad_id => $r->shows]);
        $ok = fn (Model $ad, int $cap) => ! in_array(self::key($ad), $exclude, true) && ($shows[self::key($ad)] ?? 0) < $cap;

        // ponytail: filters running ads in PHP; fine for dozens of campaigns, index targets if it grows
        $ad = SponsoredDispatch::running()->whereNotIn('id', self::hidden('dispatch'))->get()
            ->filter(fn (SponsoredDispatch $ad) => self::target($ad, $stops, $line) && $ok($ad, $ad->daily_cap))
            ->concat(BallotPromotion::running()->with('ballot')->whereNotIn('id', self::hidden('party'))->get()
                ->filter(fn (BallotPromotion $p) => $ok($p, self::PARTY_DAILY_CAP)))
            ->shuffle()->first();

        if ($ad) {
            self::record($ad);
        }

        return $ad;
    }

    /**
     * Why a sponsored dispatch matches a feed: ['stop', code], ['line', key], ['all'], or null.
     *
     * @param  array<int, string>  $stops
     * @return array<int, string>|null
     */
    public static function target(SponsoredDispatch $ad, array $stops, ?string $line): ?array
    {
        if (! $ad->stops && ! $ad->lines) {
            return ['all'];
        }
        if ($stop = collect($ad->stops)->intersect($stops)->first()) {
            return ['stop', $stop];
        }

        return $line && in_array($line, $ad->lines ?? [], true) ? ['line', $line] : null;
    }

    /**
     * The "Why am I seeing this?" answer. Only ever the feed's place or topic.
     *
     * @param  array<int, string>  $stops
     */
    public static function why(Model $ad, array $stops, ?string $line): string
    {
        $target = match (true) {
            $ad instanceof SponsoredDispatch => self::target($ad, $stops, $line) ?? ['all'],
            $ad instanceof Sponsorship => $ad->stop ? ['stop', $ad->stop] : ['line', $ad->line],
            default => ['party'],
        };

        return match ($target[0]) {
            'stop' => 'Shown to people near '.config("stoop.stations.{$target[1]}").'.',
            'line' => 'Shown on the '.config("stoop.lines.{$target[1]}.name").' topic.',
            'party' => 'From the magazine: shown to everyone while '.$ad->ballot->title.' is on.',
            default => 'Shown to everyone reading Stoop.',
        };
    }

    /**
     * Count an impression once per viewer per day; later showings only count toward the cap.
     */
    public static function record(Model $ad): void
    {
        $row = ['kind' => self::kindOf($ad), 'ad_id' => $ad->id, 'viewer' => self::viewer(), 'day' => self::today()];

        if (! DB::table('ad_views')->insertOrIgnore($row + ['shows' => 1])) {
            DB::table('ad_views')->where($row)->increment('shows');

            return;
        }

        $ad->increment('impressions');
        self::bump($row['kind'], $ad->id, 'impressions');
        // Dedupe only needs today's rows; clear out old ones now and then.
        Lottery::odds(1, 200)->winner(fn () => DB::table('ad_views')->where('day', '<', self::today())->delete())->choose();
    }

    public static function click(Model $ad): void
    {
        $ad->increment('clicks');
        self::bump(self::kindOf($ad), $ad->id, 'clicks');
    }

    /**
     * "Hide this ad", or a report (a hide with a reason). Guests' hides last for the session.
     */
    public static function hide(string $kind, int $id, ?User $user, ?string $reason = null): void
    {
        abort_unless(self::find($kind, $id), 404);
        abort_if($reason !== null && (! $user || ! isset(self::REPORT_REASONS[$reason])), 403);

        if ($user) {
            DB::table('ad_hides')->insertOrIgnore(['kind' => $kind, 'ad_id' => $id, 'user_id' => $user->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            session()->push("ads.hidden.$kind", $id);
        }
    }

    /**
     * @return array<int, int>
     */
    public static function hidden(string $kind): array
    {
        return Auth::id()
            ? DB::table('ad_hides')->where('kind', $kind)->where('user_id', Auth::id())->pluck('ad_id')->all()
            : session("ads.hidden.$kind", []);
    }

    /**
     * Impressions, clicks and CTR by day for one ad.
     *
     * @return Collection<int, object>
     */
    public static function report(string $kind, int $id): Collection
    {
        return DB::table('ad_daily')->where('kind', $kind)->where('ad_id', $id)->orderBy('day')->get()
            ->map(fn ($r) => (object) ['day' => $r->day, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks, 'ctr' => self::ctr($r->clicks, $r->impressions)]);
    }

    /**
     * Every running and upcoming placement for the sales inventory, soonest end first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function inventory(): Collection
    {
        $today = self::today();
        $feedback = DB::table('ad_hides')->groupBy('kind', 'ad_id')
            ->selectRaw('kind, ad_id, count(*) as hides, sum(case when reason is null then 0 else 1 end) as reports')
            ->get()->keyBy(fn ($r) => $r->kind.':'.$r->ad_id);
        $row = fn (string $kind, Model $m, string $type, string $name, string $where, $starts, $ends, ?int $impressions, ?int $goal, ?int $clicks) => [
            'kind' => $kind, 'id' => $m->id, 'type' => $type, 'name' => $name, 'where' => $where,
            'starts' => $starts?->format('Y-m-d'), 'ends' => $ends?->format('Y-m-d'),
            'status' => $starts && $starts->toDateString() > $today ? 'upcoming' : 'live',
            'impressions' => $impressions, 'goal' => $goal, 'clicks' => $clicks,
            'ctr' => $impressions === null || $clicks === null ? null : self::ctr($clicks, $impressions),
            'hides' => (int) ($feedback["$kind:{$m->id}"]->hides ?? 0), 'reports' => (int) ($feedback["$kind:{$m->id}"]->reports ?? 0),
            'has_report' => isset(self::KINDS[$kind]),
        ];
        $place = fn (?string $stop, ?string $line) => $stop ? $stop.' · '.config("stoop.stations.$stop") : ($line ? config("stoop.lines.$line.name").' topic' : 'Everywhere');

        return collect()
            ->concat(Sponsorship::whereDate('ends_on', '>=', $today)->get()->map(fn (Sponsorship $s) => $row('sponsorship', $s, 'Sponsored stop/line', $s->sponsor.': '.$s->headline, $place($s->stop, $s->line), $s->starts_on, $s->ends_on, $s->impressions, null, $s->clicks)))
            ->concat(SponsoredDispatch::where('ends_at', '>=', now())->get()->map(fn (SponsoredDispatch $a) => $row('dispatch', $a, $a->promoted ? 'Promoted post' : 'Sponsored dispatch', $a->advertiser.': '.str($a->body)->limit(60), self::targets($a), $a->starts_at, $a->ends_at, $a->impressions, $a->goal, $a->clicks)))
            ->concat(BallotPromotion::with('ballot')->get()->filter(fn ($p) => $p->event_on->toDateString() >= $today || $p->ballot->closes_at->isFuture())
                ->map(fn (BallotPromotion $p) => $row('party', $p, 'Party tickets', $p->title, 'Everywhere · '.$p->ballot->title, $p->ballot->opens_at, $p->event_on->max($p->ballot->closes_at), $p->impressions, null, $p->clicks)))
            ->concat(Dispatch::topLevel()->where('type', 'callout')->whereNotNull('sponsor')->where('status', 'approved')->where('created_at', '>=', now()->subDays(30))->get()
                ->map(fn (Dispatch $d) => $row('callout', $d, 'Sponsored callout', $d->sponsor.': '.str($d->body)->limit(60), $place($d->stop, $d->line), $d->created_at, $d->closes_at, null, null, null)))
            ->concat(Happening::with('dispatch')->where('featured', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->subDay()))->get()
                ->filter(fn (Happening $h) => $h->dispatch?->status === 'approved')
                ->map(fn (Happening $h) => $row('happening', $h, 'Featured happening', str($h->dispatch->body)->limit(60), $place($h->dispatch->stop, $h->dispatch->line), $h->created_at, $h->starts_at, null, null, $h->isTicketed() ? (int) $h->ticket_clicks : null)))
            ->concat(Dispatch::topLevel()->where('type', 'openhouse')->where('details->sponsored', true)->where('status', 'approved')->where('details->date', '>=', $today)->get()
                ->map(fn (Dispatch $d) => $row('openhouse', $d, 'Sponsored open house', $d->details['block'] ?? 'Open house', $place($d->stop, null), $d->created_at, Carbon::parse($d->details['date']), null, null, null)))
            ->concat(Presenter::whereDate('ends_on', '>=', $today)->get()->map(fn (Presenter $p) => $row('presenter', $p, 'Presented by', $p->sponsor, $p->targetLabel(), $p->starts_on, $p->ends_on, $p->impressions, null, $p->clicks)))
            ->concat(MeetupSponsor::with('event', 'place')->whereHas('event', fn ($q) => $q->where('ends_at', '>=', now()))->get()
                ->map(fn (MeetupSponsor $m) => $row('meetup', $m, 'Sponsored meetup', $m->place->name.': '.$m->event->name, $place($m->event->stop, null), $m->event->starts_at, $m->event->ends_at, $m->impressions, null, $m->clicks)))
            ->sortBy('ends')->values();
    }

    public static function targets(SponsoredDispatch $ad): string
    {
        $where = [...$ad->stops ?? [], ...array_map(fn ($l) => config("stoop.lines.$l.name").' topic', $ad->lines ?? [])];

        return $where ? implode(', ', $where) : 'Everywhere';
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  The first row is the header.
     */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // Spreadsheet apps run cells starting with = + - @ as formulas; make them plain text.
            fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v, $row));
        }
        rewind($out);

        return stream_get_contents($out);
    }

    public static function ctr(int|string $clicks, int|string $impressions): ?float
    {
        return $impressions ? round($clicks / $impressions * 100, 2) : null;
    }

    protected static function key(Model $ad): string
    {
        return self::kindOf($ad).':'.$ad->id;
    }

    protected static function bump(string $kind, int $id, string $column): void
    {
        $row = ['kind' => $kind, 'ad_id' => $id, 'day' => self::today()];
        DB::table('ad_daily')->insertOrIgnore($row);
        DB::table('ad_daily')->where($row)->increment($column);
    }

    protected static function today(): string
    {
        return now(config('stoop.timezone'))->toDateString();
    }
}
