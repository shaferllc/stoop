<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live rail incidents from WMATA, cached for two minutes. Needs a free WMATA API key.
 */
class Metro
{
    protected const LINE_KEYS = ['RD' => 'red', 'OR' => 'orange', 'BL' => 'blue', 'GR' => 'green', 'YL' => 'yellow', 'SV' => 'silver'];

    /**
     * @return array<int, array{line: string, message: string}>|null Null when no key is set or WMATA is unreachable.
     */
    public static function incidents(): ?array
    {
        if (! $key = config('services.wmata.key')) {
            return null;
        }

        return Cache::remember('wmata:incidents', now()->addMinutes(2), function () use ($key) {
            try {
                $incidents = Http::withHeaders(['api_key' => $key])->timeout(4)
                    ->get('https://api.wmata.com/Incidents.svc/json/Incidents')->throw()->json('Incidents', []);
            } catch (Throwable) {
                return null;
            }

            return collect($incidents)->flatMap(fn ($i) => collect(preg_split('/[;\s]+/', (string) $i['LinesAffected'], -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($code) => ['line' => self::LINE_KEYS[$code] ?? null, 'message' => (string) $i['Description']]))
                ->filter(fn ($i) => $i['line'])->values()->all();
        });
    }

    /**
     * The next trains at a station, from WMATA's real-time predictions. Null without a key.
     *
     * @return array<int, array{line: ?string, destination: string, minutes: string}>|null
     */
    public static function nextTrains(string $code): ?array
    {
        if (! $key = config('services.wmata.key')) {
            return null;
        }

        // Transfer stations have a code per level (Metro Center is A01 and C01); ask for all of them.
        $codes = collect([$code])->merge(array_keys(config('metro.aliases'), $code))->join(',');

        return Cache::remember("wmata:trains:$codes", now()->addSeconds(20), function () use ($key, $codes) {
            try {
                $trains = Http::withHeaders(['api_key' => $key])->timeout(4)
                    ->get("https://api.wmata.com/StationPrediction.svc/json/GetPrediction/$codes")->throw()->json('Trains', []);
            } catch (Throwable) {
                return null;
            }

            return collect($trains)
                ->filter(fn ($t) => ($t['Destination'] ?? '') !== '' && ! in_array($t['Line'] ?? '', ['', '--', 'No'], true))
                ->map(fn ($t) => [
                    'line' => self::LINE_KEYS[$t['Line']] ?? null,
                    'destination' => (string) $t['Destination'],
                    'minutes' => (string) $t['Min'],
                ])->take(6)->values()->all();
        });
    }

    /**
     * Capital Bikeshare docks within about 500 meters of a station, nearest first, from the
     * public GBFS feed (no key needed). Null if the feed can't be reached.
     *
     * @return array<int, array{name: string, bikes: int, ebikes: int, docks: int, meters: int}>|null
     */
    public static function bikeshare(string $code): ?array
    {
        $at = config("metro_coords.$code");
        if (! $at) {
            return null;
        }

        try {
            $stations = Cache::remember('cabi:info', now()->addDay(), fn () => collect(Http::timeout(5)
                ->get('https://gbfs.lyft.com/gbfs/2.3/dca-cabi/en/station_information.json')->throw()->json('data.stations', []))
                ->map(fn ($s) => [$s['station_id'], $s['name'], $s['lat'], $s['lon']])->all());
            $status = Cache::remember('cabi:status', now()->addMinute(), fn () => collect(Http::timeout(5)
                ->get('https://gbfs.lyft.com/gbfs/2.3/dca-cabi/en/station_status.json')->throw()->json('data.stations', []))
                ->mapWithKeys(fn ($s) => [$s['station_id'] => [
                    (int) ($s['num_bikes_available'] ?? 0),
                    (int) collect($s['vehicle_types_available'] ?? [])->where('vehicle_type_id', '2')->sum('count'),
                    (int) ($s['num_docks_available'] ?? 0),
                    (bool) ($s['is_renting'] ?? true),
                ]])->all());
        } catch (Throwable) {
            return null;
        }

        return collect($stations)
            ->map(fn ($s) => [...$s, self::meters($at[0], $at[1], $s[2], $s[3])])
            ->filter(fn ($s) => $s[4] <= 500 && ($status[$s[0]][3] ?? false))
            ->sortBy(fn ($s) => $s[4])->take(3)
            ->map(fn ($s) => ['name' => trim($s[1]), 'bikes' => $status[$s[0]][0], 'ebikes' => $status[$s[0]][1], 'docks' => $status[$s[0]][2], 'meters' => (int) round($s[4])])
            ->values()->all();
    }

    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lon2 - $lon1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
