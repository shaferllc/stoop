<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @311 tells people how to report a city problem, and on Sundays sums up what DC closed out
 * near busy Metro stops, from DC's public 311 data.
 */
class Dc311Bot extends Bot
{
    // Issue => [311 request type, words that mean it]. Types match DC's 311 data.
    public const TYPES = [
        'pothole' => ['Pothole', ['pothole']],
        'streetlight' => ['Streetlight Repair Investigation', ['streetlight', 'street light', 'light out', 'lamp']],
        'trash' => ['Trash Collection - Missed', ['trash', 'garbage', 'recycling', 'pickup']],
        'rats' => ['DC Health Rodent & Vector Control', ['rat', 'rodent', 'mice', 'mouse']],
        'graffiti' => ['Graffiti Removal', ['graffiti']],
        'parking' => ['Parking Enforcement', ['parking', 'parked', 'blocking']],
        'tree' => ['Tree Inspection', ['tree', 'branch', 'limb']],
    ];

    // DC's 311 requests from the last 90 days (DC Open Data, no key needed).
    public const LAYER = 'https://maps2.dcgis.dc.gov/dcgis/rest/services/DCGIS_DATA/ServiceRequests/FeatureServer/13/query';

    // ponytail: a fixed set of busy stops far enough apart that 500 m circles don't overlap; rank by ridership if that ever matters.
    public const BUSY = ['A01', 'B03', 'A03', 'E04', 'F05'];

    public function username(): string
    {
        return '311';
    }

    public function name(): string
    {
        return 'DC 311';
    }

    public function description(): string
    {
        return 'How to report potholes, rats, trash and more to DC 311. Reply “@311 pothole near Navy Yard”.';
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $text = preg_replace('/(?<![\w.@])@311\b/i', '', $dispatch->body);
        [$issue, $near] = array_pad(preg_split('/\b(?:near|at|by|around|outside)\b/i', $text, 2), 2, '');
        $type = collect(self::TYPES)->first(fn ($t) => preg_match('/\b('.implode('|', $t[1]).')s?\b/i', $issue));
        $code = trim($near) !== '' ? Stations::find($near) : null;

        if (! $type) {
            return 'I can point you to the right 311 request for a pothole, streetlight, trash, rats, graffiti, parking or a tree. Try “@311 pothole near Navy Yard”, “@311 streetlight out near Shaw”, “@311 missed trash near Eastern Market”, “@311 rats near Columbia Heights”, “@311 graffiti by Union Station”, “@311 car blocking parking near Dupont” or “@311 tree branch down near Takoma”. '
                .'Or call 311 (202-737-4404 from outside DC) or go to https://311.dc.gov';
        }

        if ($code && Stations::jurisdiction($code) !== 'DC') {
            return Stations::name($code).' is outside DC, so DC 311 can’t help there. Try the county’s own 311 line.';
        }

        return "Report it at https://311.dc.gov as “{$type[0]}”".($code ? ' with the closest street address near '.Stations::name($code) : ' with the closest street address')
            .', or call 311 (202-737-4404). You’ll get a request number to track it.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->weeklyPost())->weeklyOn(0, '11:00')->timezone(config('stoop.timezone'))->name('bot:311-weekly');
    }

    /**
     * What DC closed out in the past week within 500 m of busy stops. Skips the week if the
     * data can't be reached or nothing was closed.
     */
    public function weeklyPost(): ?Dispatch
    {
        $since = now()->subWeek()->utc()->format('Y-m-d H:i:s');
        $kinds = [];
        $stops = [];

        foreach (self::BUSY as $code) {
            [$lat, $lon] = config("metro_coords.$code");

            try {
                $rows = Http::timeout(10)->get(self::LAYER, [
                    'where' => "SERVICEORDERSTATUS='Closed' AND RESOLUTIONDATE >= TIMESTAMP '$since'",
                    'geometry' => "$lon,$lat", 'geometryType' => 'esriGeometryPoint', 'inSR' => 4326,
                    'distance' => 500, 'units' => 'esriSRUnit_Meter', 'spatialRel' => 'esriSpatialRelIntersects',
                    'groupByFieldsForStatistics' => 'SERVICECODEDESCRIPTION',
                    'outStatistics' => '[{"statisticType":"count","onStatisticField":"OBJECTID","outStatisticFieldName":"n"}]',
                    'f' => 'json',
                ])->throw()->json('features');
            } catch (Throwable $e) {
                Log::warning('@311 could not reach DC 311 data', ['error' => $e->getMessage()]);

                return null;
            }

            if (! is_array($rows)) {
                return null;
            }

            foreach ($rows as $row) {
                $kind = (string) ($row['attributes']['SERVICECODEDESCRIPTION'] ?? '');
                $count = (int) ($row['attributes']['N'] ?? $row['attributes']['n'] ?? 0);
                if ($kind !== '' && ! str_starts_with($kind, 'DMV')) {
                    $kinds[$kind] = ($kinds[$kind] ?? 0) + $count;
                    $stops[$code] = ($stops[$code] ?? 0) + $count;
                }
            }
        }

        if (! $total = array_sum($kinds)) {
            return null;
        }

        if (! Cache::add('311:weekly:'.now(config('stoop.timezone'))->toDateString(), true, now()->addDays(6))) {
            return null;
        }

        arsort($kinds);
        arsort($stops);
        $busiest = array_key_first($stops);
        $top = collect($kinds)->take(3)->map(fn ($n, $kind) => "$n ".Str::lower($kind))->join(', ', ' and ');

        try {
            return $this->post([
                'line' => 'yellow',
                'stop' => $busiest,
                'body' => "This week DC closed $total 311 requests within 500 m of ".collect(self::BUSY)->map(fn ($c) => Stations::name($c))->join(', ', ' and ')
                    .", including $top. The most were near ".Stations::name($busiest)." ({$stops[$busiest]}).",
            ]);
        } catch (Throwable $e) {
            Log::warning('@311 could not post', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
