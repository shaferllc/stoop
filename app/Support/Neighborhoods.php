<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wards, ANCs and DC 311 around DC Metro stations (see config/neighborhoods.php).
 */
class Neighborhoods
{
    public static function ward(string $code): ?int
    {
        return config("neighborhoods.stations.$code.0");
    }

    public static function anc(string $code): ?string
    {
        return config("neighborhoods.stations.$code.1");
    }

    /**
     * The ANC's page on oanc.dc.gov, which lists its commissioners and meetings.
     */
    public static function ancUrl(string $anc): string
    {
        $term = config('neighborhoods.anc_pages.'.strtoupper($anc));

        return $term ? "https://oanc.dc.gov/taxonomy/term/$term" : 'https://oanc.dc.gov/';
    }

    /**
     * @return array<int, string>
     */
    public static function stationsInWard(int $ward): array
    {
        return array_keys(array_filter(config('neighborhoods.stations'), fn ($s) => $s[0] === $ward));
    }

    /**
     * @return array<int, string>
     */
    public static function ancs(): array
    {
        return array_keys(config('neighborhoods.anc_pages'));
    }

    public static function wardOfAnc(string $anc): int
    {
        return (int) $anc[0];
    }

    /**
     * Pepco serves DC and the Maryland suburbs; Virginia is Dominion's.
     */
    public static function pepco(string $code): bool
    {
        return in_array(Stations::jurisdiction($code), ['DC', 'MD'], true);
    }

    /**
     * The most recent DC 311 requests within about 400 meters of a DC station, from the
     * last 90 days. Null when it's turned off, outside DC, or DC GIS can't be reached.
     *
     * @return array<int, array{type: string, address: string, status: string, opened_at: string}>|null
     */
    public static function serviceRequests(string $code): ?array
    {
        $at = config("metro_coords.$code");
        if (! config('neighborhoods.dc311') || ! $at || ! self::ward($code)) {
            return null;
        }

        try {
            $features = Cache::remember("dc311:$code", now()->addMinutes(20), fn () => Http::timeout(5)->get(config('neighborhoods.dc311_url'), [
                'geometry' => $at[1].','.$at[0], // ArcGIS wants x (longitude) first.
                'geometryType' => 'esriGeometryPoint',
                'inSR' => 4326,
                'distance' => 400,
                'units' => 'esriSRUnit_Meter',
                'outFields' => 'SERVICECODEDESCRIPTION,STREETADDRESS,ADDDATE,SERVICEORDERSTATUS',
                'orderByFields' => 'ADDDATE DESC',
                'resultRecordCount' => 5,
                'returnGeometry' => 'false',
                'f' => 'json',
            ])->throw()->json('features') ?? throw new \RuntimeException('DC GIS returned no features'));
        } catch (Throwable) {
            return null;
        }

        return collect($features)->map(fn ($f) => $f['attributes'] ?? [])
            ->filter(fn ($a) => ($a['SERVICECODEDESCRIPTION'] ?? '') !== '')
            ->map(fn ($a) => [
                'type' => trim($a['SERVICECODEDESCRIPTION']),
                'address' => preg_replace_callback('/\b(nw|ne|sw|se)$/i', fn ($m) => strtoupper($m[1]), ucwords(strtolower(trim($a['STREETADDRESS'] ?? '')))),
                'status' => trim($a['SERVICEORDERSTATUS'] ?? ''),
                'opened_at' => isset($a['ADDDATE']) ? Carbon::createFromTimestampMs($a['ADDDATE'])->toIso8601String() : '',
            ])->values()->all();
    }
}
