<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservation and ticket links that may carry a partner parameter (config/referrals.php).
 * Every click goes through a Stoop redirect that only ever sends people to the link stored
 * on the place or happening, and counts it by place or happening, provider and DC day.
 */
class Referrals
{
    public const COMMISSION = 'Stoop may earn a commission';

    /**
     * The provider a link belongs to, by its host: OpenTable, Resy, Tock, Eventbrite, or the fallback.
     */
    public static function provider(string $url, string $fallback = 'website'): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (config('referrals.providers') as $key => $provider) {
            foreach ($provider['hosts'] as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $key;
                }
            }
        }

        return $fallback;
    }

    public static function name(string $provider): string
    {
        return config("referrals.providers.$provider.name", ucfirst($provider));
    }

    /**
     * Whether links to this provider carry a partner parameter right now.
     */
    public static function earns(?string $url, string $fallback = 'website'): bool
    {
        if (! $url) {
            return false;
        }
        $provider = config('referrals.providers.'.self::provider($url, $fallback));

        return filled($provider['param'] ?? null) && filled($provider['value'] ?? null);
    }

    public static function rel(?string $url, string $fallback = 'website'): string
    {
        return self::earns($url, $fallback) ? 'sponsored noopener' : 'nofollow noopener';
    }

    /**
     * The link with the provider's partner parameter added, when one is configured.
     */
    public static function withPartner(string $url, string $fallback = 'website'): string
    {
        if (! self::earns($url, $fallback)) {
            return $url;
        }
        $provider = config('referrals.providers.'.self::provider($url, $fallback));
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query([$provider['param'] => $provider['value']]).($fragment !== null ? '#'.$fragment : '');
    }

    /**
     * Rules for a reservation link. The provider comes from the link's host, so a link can't
     * be labeled OpenTable unless it really goes to opentable.com.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'url:http,https', 'max:500'];
    }

    /**
     * Count the click, then send people to the stored link (never a link from the request).
     */
    public static function go(string $url, string $fallback, int $placeId = 0, int $dispatchId = 0): RedirectResponse
    {
        abort_unless(in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true), 404);

        $provider = self::provider($url, $fallback);
        $now = now();
        DB::table('referral_clicks')->upsert(
            [['day' => now(config('stoop.timezone'))->toDateString(), 'place_id' => $placeId, 'dispatch_id' => $dispatchId, 'provider' => $provider, 'clicks' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['day', 'place_id', 'dispatch_id', 'provider'],
            ['clicks' => DB::raw('clicks + 1'), 'updated_at' => $now],
        );

        return redirect()->away(self::withPartner($url, $fallback));
    }

    public static function reserve(Place $place): RedirectResponse
    {
        abort_unless($place->reservation_url, 404);

        return self::go($place->reservation_url, 'website', placeId: $place->id);
    }

    /**
     * Clicks by DC day and provider in the period, newest first.
     *
     * @return Collection<int, object{day: string, provider: string, clicks: int}>
     */
    public static function byDay(string $from, string $to): Collection
    {
        return DB::table('referral_clicks')->whereBetween('day', [$from, $to])
            ->groupBy('day', 'provider')->selectRaw('day, provider, sum(clicks) as clicks')
            ->orderByDesc('day')->orderBy('provider')->get();
    }

    /**
     * The places and happenings with the most clicks in the period.
     *
     * @return Collection<int, array{name: string, provider: string, clicks: int}>
     */
    public static function top(string $from, string $to, int $take = 20): Collection
    {
        return DB::table('referral_clicks')->whereBetween('day', [$from, $to])
            ->leftJoin('places', 'places.id', '=', 'referral_clicks.place_id')
            ->leftJoin('dispatches', 'dispatches.id', '=', 'referral_clicks.dispatch_id')
            ->groupBy('referral_clicks.place_id', 'referral_clicks.dispatch_id', 'referral_clicks.provider', 'places.name', 'dispatches.body')
            ->selectRaw('places.name as place, dispatches.body as happening, referral_clicks.provider, sum(clicks) as clicks')
            ->orderByDesc('clicks')->limit($take)->get()
            ->map(fn ($r) => ['name' => $r->place ?? ($r->happening ? 'Happening: '.str($r->happening)->limit(60) : 'Deleted'), 'provider' => $r->provider, 'clicks' => (int) $r->clicks]);
    }
}
