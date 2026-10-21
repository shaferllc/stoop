<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recent home sales near Metro stations, from DC's public property records (the Office of
 * Tax and Revenue's sales, on DC GIS's Owner Polygons layer; no key needed). Each sale is
 * matched to the nearest station within 1 km. Only the block is stored: never the house
 * number, the unit or anyone's name, and the owner fields are never even requested.
 */
class HomeSales
{
    public const SOURCE = 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/Property_and_Land_WebMercator/MapServer/40/query';

    // Sales within this distance of a station count as near it.
    public const RADIUS = 1000;

    /**
     * Fetch market sales from the last 120 days (DC records lag a few weeks) and store new ones.
     * Returns how many were stored or updated; a failure keeps what's already there.
     */
    public static function import(): int
    {
        $since = now(config('stoop.timezone'))->subDays(120)->toDateString();
        $stored = 0;
        $offset = 0;

        try {
            do {
                $response = Http::timeout(30)->get(self::SOURCE, [
                    'where' => "SALEDATE >= DATE '$since' AND ACCEPTCODE = 'Market Sale' AND SALEPRICE > 0",
                    'outFields' => 'SSL,LOWNUMBER,STREETNAME,QDRNTNAME,SALEPRICE,SALEDATE',
                    'returnGeometry' => 'true',
                    'outSR' => 4326,
                    'geometryPrecision' => 6,
                    'maxAllowableOffset' => 0.0001,
                    'orderByFields' => 'OBJECTID',
                    'resultOffset' => $offset,
                    'resultRecordCount' => 1000,
                    'f' => 'json',
                ])->throw();

                $features = $response->json('features') ?? [];
                foreach ($features as $feature) {
                    $stored += (int) self::store($feature['attributes'] ?? [], $feature['geometry']['rings'][0] ?? []);
                }
                $offset += count($features);
            } while ($features && $response->json('exceededTransferLimit') && $offset < 20000);
        } catch (Throwable $e) {
            Log::warning('Home sales import failed', ['error' => $e->getMessage()]);
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $sale
     * @param  array<int, array{0: float, 1: float}>  $ring  [lng, lat] points of the lot's outline
     */
    protected static function store(array $sale, array $ring): bool
    {
        if (! $ring || empty($sale['SSL']) || empty($sale['STREETNAME']) || ! ($sale['SALEPRICE'] ?? 0) || ! ($sale['SALEDATE'] ?? null)) {
            return false;
        }

        // The average of the outline's corners is close enough to the lot's middle at block scale.
        $near = self::nearest(collect($ring)->avg(1), collect($ring)->avg(0));
        if (! $near) {
            return false;
        }

        DB::table('home_sales')->upsert([[
            'ssl' => trim(preg_replace('/\s+/', ' ', $sale['SSL'])),
            'block' => self::block(trim(($sale['LOWNUMBER'] ?? '').' '.$sale['STREETNAME'].' '.($sale['QDRNTNAME'] ?? ''))),
            'price' => (int) round($sale['SALEPRICE']),
            'sold_on' => Carbon::createFromTimestampMs($sale['SALEDATE'], 'UTC')->setTimezone(config('stoop.timezone'))->toDateString(),
            'stop' => $near[0],
            'meters' => $near[1],
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['ssl', 'sold_on'], ['block', 'price', 'stop', 'meters', 'updated_at']);

        return true;
    }

    /**
     * The nearest station within RADIUS, as [code, meters], or null.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function nearest(float $lat, float $lng): ?array
    {
        $best = collect(config('metro_coords'))->only(array_keys(config('stoop.stations')))
            ->map(fn ($at) => Metro::meters($lat, $lng, $at[0], $at[1]))
            ->sort()->take(1);

        return $best->isNotEmpty() && $best->first() <= self::RADIUS ? [$best->keys()->first(), (int) round($best->first())] : null;
    }

    /**
     * An address rounded to its block: "1428 Q ST NW" becomes "1400 block of Q St NW", and
     * numbers under 100 are the "Unit block". Anything after a comma (a unit, the city) is dropped.
     */
    public static function block(string $address): string
    {
        $address = trim(preg_replace('/\s+/', ' ', explode(',', $address)[0]));
        preg_match('/^(\d+)[A-Za-z\-\d\/]*\s+(.+)$/', $address, $m);
        $street = $m[2] ?? $address;

        $street = preg_replace_callback('/\b(nw|ne|sw|se)\.?$/i', fn ($q) => strtoupper(rtrim($q[1], '.')), ucwords(strtolower($street)));

        if (! isset($m[1])) {
            return $street;
        }

        $hundred = intdiv((int) $m[1], 100) * 100;

        return ($hundred ? $hundred : 'Unit').' block of '.$street;
    }

    /**
     * Sales near a station in the last 90 days: count, median price and the 5 most recent.
     *
     * @return array{count: int, median: int, recent: Collection<int, object>}|null
     */
    public static function summary(string $stop, int $days = 90): ?array
    {
        $sales = DB::table('home_sales')->where('stop', $stop)
            ->where('sold_on', '>=', now(config('stoop.timezone'))->subDays($days)->toDateString())
            ->orderByDesc('sold_on')->orderByDesc('id')->get(['block', 'price', 'sold_on']);

        return $sales->isEmpty() ? null : [
            'count' => $sales->count(),
            'median' => (int) round($sales->median('price')),
            'recent' => $sales->take(5),
        ];
    }

    /**
     * The stations with the most sales in the last $days days, busiest first.
     *
     * @return Collection<string, array{count: int, median: int}>
     */
    public static function busiest(int $days = 30, int $min = 5, int $take = 3): Collection
    {
        return DB::table('home_sales')->where('sold_on', '>=', now(config('stoop.timezone'))->subDays($days)->toDateString())
            ->get(['stop', 'price'])->groupBy('stop')
            ->filter(fn ($sales) => $sales->count() >= $min)
            ->map(fn ($sales) => ['count' => $sales->count(), 'median' => (int) round($sales->median('price'))])
            ->sortByDesc('count')->take($take);
    }
}
