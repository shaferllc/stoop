<?php

namespace App\Support;

use App\Models\Area;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly reports on a neighborhood area (a group of stations) for business improvement
 * districts and developers. Built on ReaderInsights and held to the same rules: real readers
 * only (no bots, staff or house accounts), no names or posts, and any number drawn from fewer
 * than K people (or, for home sales, fewer than K sales) shows as "<10". Breakdowns show
 * people per row, which overlap, so no hidden cell can be worked out by subtraction.
 */
class AreaReports
{
    /**
     * The DC month "2026-10" as UTC bounds; anything else means last month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function month(string $month): array
    {
        $tz = config('stoop.timezone');
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('!Y-m', $month, $tz)
            : now($tz)->subMonthNoOverflow()->startOfMonth();

        return [$start->copy()->startOfMonth()->utc(), $start->copy()->endOfMonth()->utc()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(Area $area, string $month): array
    {
        [$from, $to] = self::month($month);
        $tz = config('stoop.timezone');
        $stops = $area->stations;
        $posts = ReaderInsights::posts($from, $to)->whereIn('stop', $stops);
        $people = $posts->unique('user_id')->count();
        $city = ReaderInsights::posts($from, $to);
        $cityPeople = $city->unique('user_id')->count();
        $lines = config('stoop.lines');

        $checkins = DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->join('places', 'places.id', '=', 'dispatches.place_id')
            ->where('marks.kind', 'been')->whereIn('marks.user_id', ReaderInsights::readers())
            ->whereBetween('marks.created_at', [$from, $to]);
        $areaCheckins = (clone $checkins)->whereIn('places.stop', $stops);

        $events = DB::table('dispatches')->where('type', 'happening')->whereNull('parent_id')->where('status', 'approved')->whereNull('deleted_at')
            ->whereIn('stop', $stops)->whereBetween('created_at', [$from, $to]);
        $rsvps = DB::table('marks')->whereIn('kind', ['going', 'interested'])->whereIn('user_id', ReaderInsights::readers())
            ->whereIn('dispatch_id', (clone $events)->select('id'));

        $sales = DB::table('home_sales')->whereBetween('sold_on', [$from->copy()->timezone($tz)->toDateString(), $to->copy()->timezone($tz)->toDateString()]);
        $areaPrices = (clone $sales)->whereIn('stop', $stops)->pluck('price');
        $cityPrices = $sales->pluck('price');

        $areaCheckinPeople = (int) (clone $areaCheckins)->distinct()->count('marks.user_id');
        $cityCheckinPeople = (int) (clone $checkins)->distinct()->count('marks.user_id');

        return [
            'area' => $area->name,
            'stations' => $area->stationNames(),
            'verify' => $area->verify,
            'month' => $from->copy()->timezone($tz)->format('F Y'),
            'from' => $from->copy()->timezone($tz)->toDateString(),
            'to' => $to->copy()->timezone($tz)->toDateString(),
            'totals' => [
                'posts' => ReaderInsights::cell($posts->count(), $people),
                'people' => ReaderInsights::cell($people, $people),
                'sentiment' => ReaderInsights::cell(ReaderInsights::sentiment($posts->pluck('body')), $people),
            ],
            'trend' => collect(range(5, 0))->map(function ($back) use ($from, $stops, $tz) {
                $start = $from->copy()->timezone($tz)->subMonthsNoOverflow($back)->startOfMonth();
                $rows = ReaderInsights::posts($start->copy()->utc(), $start->copy()->endOfMonth()->utc())->whereIn('stop', $stops);
                $n = $rows->unique('user_id')->count();

                return ['month' => $start->format('M Y'), 'posts' => ReaderInsights::cell($rows->count(), $n), 'people' => ReaderInsights::cell($n, $n)];
            })->all(),
            'topics' => $posts->groupBy('line')->map(fn ($rows, $line) => ['topic' => $lines[$line]['name'] ?? $line, 'people' => $rows->unique('user_id')->count()])
                ->filter(fn ($row) => $row['people'] >= ReaderInsights::K)->sortByDesc('people')->values()->all(),
            'checkins' => (clone $areaCheckins)->groupBy('places.category')
                ->selectRaw('places.category as category, count(*) as checkins, count(distinct marks.user_id) as people')
                ->orderByDesc('people')->get()
                ->map(fn ($r) => ['category' => $r->category ?: 'Other', 'checkins' => ReaderInsights::cell((int) $r->checkins, (int) $r->people), 'people' => ReaderInsights::cell((int) $r->people, (int) $r->people)])->all(),
            'events' => [[
                'events' => ReaderInsights::cell($events->count(), $hosts = (int) (clone $events)->distinct()->count('user_id')),
                'hosts' => ReaderInsights::cell($hosts, $hosts),
                'rsvps' => ReaderInsights::cell((clone $rsvps)->count(), $rsvpPeople = (int) (clone $rsvps)->distinct()->count('user_id')),
                'people_rsvping' => ReaderInsights::cell($rsvpPeople, $rsvpPeople),
            ]],
            'home_sales' => [[
                'sales' => ReaderInsights::cell($areaPrices->count(), $areaPrices->count()),
                'median_price' => ReaderInsights::cell((int) $areaPrices->median(), $areaPrices->count()),
                'city_median_price' => ReaderInsights::cell((int) $cityPrices->median(), $cityPrices->count()),
            ]],
            'city' => [
                ['measure' => 'Posts per active neighbor', 'area' => ReaderInsights::cell(self::ratio($posts->count(), $people), $people), 'city' => ReaderInsights::cell(self::ratio($city->count(), $cityPeople), $cityPeople)],
                ['measure' => 'Sentiment (-100 to 100)', 'area' => ReaderInsights::cell(ReaderInsights::sentiment($posts->pluck('body')), $people), 'city' => ReaderInsights::cell(ReaderInsights::sentiment($city->pluck('body')), $cityPeople)],
                ['measure' => 'Been here check-ins per person', 'area' => ReaderInsights::cell(self::ratio((clone $areaCheckins)->count(), $areaCheckinPeople), $areaCheckinPeople), 'city' => ReaderInsights::cell(self::ratio((clone $checkins)->count(), $cityCheckinPeople), $cityCheckinPeople)],
                ['measure' => 'Median home sale price', 'area' => ReaderInsights::cell((int) $areaPrices->median(), $areaPrices->count()), 'city' => ReaderInsights::cell((int) $cityPrices->median(), $cityPrices->count())],
            ],
        ];
    }

    /**
     * The report as CSV rows, one section after another.
     *
     * @return list<list<string|int|float>>
     */
    public static function csvRows(array $report): array
    {
        $rows = [['Stoop area report', $report['area'], $report['month']], ['Stations', $report['stations']], ['Cells from fewer than '.ReaderInsights::K.' people (or sales) show as '.ReaderInsights::SUPPRESSED.'.'], []];
        $rows[] = ['Totals'];
        $rows[] = array_keys($report['totals']);
        $rows[] = array_values($report['totals']);

        foreach (self::SECTIONS as $key => $title) {
            $rows[] = [];
            $rows[] = [$title];
            if ($report[$key]) {
                $rows[] = array_keys($report[$key][0]);
                foreach ($report[$key] as $row) {
                    $rows[] = array_values($row);
                }
            }
        }

        return $rows;
    }

    public const SECTIONS = [
        'trend' => 'Activity, last six months',
        'topics' => 'Top topics (people posting)',
        'checkins' => 'Been here check-ins by kind of place',
        'events' => 'Happenings and RSVPs',
        'home_sales' => 'Home sales',
        'city' => 'Compared with the city',
    ];

    protected static function ratio(int $n, int $d): float
    {
        return $d ? round($n / $d, 1) : 0;
    }
}
