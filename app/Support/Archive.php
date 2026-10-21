<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The magazine's older stories, tagged to Metro stations so station pages can show
 * "From the archive". The same tagger tags new feed articles and radar clusters.
 */
class Archive
{
    public const COLUMNS = ['title', 'url', 'date', 'section', 'stations', 'neighborhoods', 'description'];

    // Station names that are also everyday words or other places; staff can add an alias instead.
    protected const GENERIC = ['Archives', 'Waterfront', 'Pentagon', 'Smithsonian', 'Medical Center', 'Innovation Center', 'Vienna', 'Huntington', 'Greensboro'];

    /**
     * Every name the tagger looks for, with the station codes it points to: station names,
     * neighborhoods from config/metro.php (if any) and the staff's alias list.
     *
     * @return array<string, list<string>>
     */
    public static function names(): array
    {
        $names = [];
        foreach (config('stoop.stations') as $code => $name) {
            if (! in_array($name, self::GENERIC, true)) {
                $names[$name][] = $code;
            }
        }
        foreach (config('metro.neighborhoods', []) as $code => $hoods) {
            foreach ((array) $hoods as $hood) {
                $names[$hood][] = $code;
            }
        }
        foreach (DB::table('station_aliases')->get() as $alias) {
            $names[$alias->alias][] = $alias->stop;
        }

        return $names;
    }

    /**
     * Stations and neighborhood names mentioned in the text. Case-sensitive whole words,
     * so "Shawn" isn't Shaw and lowercase "archives" isn't a station.
     *
     * @param  array<string, list<string>>|null  $names
     * @return array{stations: list<string>, neighborhoods: list<string>}
     */
    public static function tag(string $text, ?array $names = null): array
    {
        // ponytail: one regex per name; fine for a few hundred names, build one alternation if imports get slow
        $text = str_replace(['‘', '’'], "'", $text);
        $stations = $hoods = [];

        foreach ($names ?? self::names() as $name => $codes) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(str_replace(['‘', '’'], "'", $name), '/').'(?![\p{L}\p{N}])/u', $text)) {
                array_push($stations, ...$codes);
                if (! in_array($name, config('stoop.stations'), true)) {
                    $hoods[] = $name;
                }
            }
        }

        return ['stations' => array_values(array_unique($stations)), 'neighborhoods' => array_values(array_unique($hoods))];
    }

    /**
     * Up to $count archive stories tagged to a station, a different few each day.
     */
    public static function forStop(string $stop, int $count = 3): Collection
    {
        return Cache::remember("archive:$stop:$count:".now()->toDateString(), now()->addDay(), fn () => DB::table('archive_stories')
            ->whereIn('id', DB::table('archive_story_stations')->where('stop', $stop)->select('archive_story_id'))
            ->inRandomOrder()->take($count)->get());
    }

    /**
     * Add or update a story by URL. Stations given explicitly win; otherwise the tagger reads
     * the title and description.
     *
     * @param  array<string, ?string>  $row
     * @param  array<string, list<string>>|null  $names
     */
    public static function save(array $row, ?array $names = null): ?int
    {
        $url = trim((string) ($row['url'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '' || ! preg_match('#^https?://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $aliases = config('metro.aliases');
        $given = collect(preg_split('/[\s,;|]+/', strtoupper((string) ($row['stations'] ?? ''))))->filter()
            ->map(fn ($code) => $aliases[$code] ?? $code)->filter(fn ($code) => config("stoop.stations.$code"));
        $tags = self::tag($title.' '.($row['description'] ?? ''), $names);
        $stations = $given->isNotEmpty() ? $given->unique()->values()->all() : $tags['stations'];
        $hoods = trim((string) ($row['neighborhoods'] ?? '')) ?: implode(', ', $tags['neighborhoods']);

        $values = [
            'title' => Str::limit($title, 300, ''),
            'description' => ($row['description'] ?? null) ?: null,
            'published_on' => rescue(fn () => ($row['date'] ?? null) ? Carbon::parse($row['date'])->toDateString() : null, null, false),
            'section' => ($row['section'] ?? null) ? Str::limit($row['section'], 80, '') : null,
            'neighborhoods' => $hoods !== '' ? Str::limit($hoods, 300, '') : null,
            'updated_at' => now(),
        ];

        $id = DB::table('archive_stories')->where('url', $url)->value('id');
        $id ? DB::table('archive_stories')->where('id', $id)->update($values)
            : $id = DB::table('archive_stories')->insertGetId([...$values, 'url' => Str::limit($url, 500, ''), 'created_at' => now()]);

        DB::table('archive_story_stations')->where('archive_story_id', $id)->delete();
        DB::table('archive_story_stations')->insert(array_map(fn ($stop) => ['archive_story_id' => $id, 'stop' => $stop], $stations));

        return $id;
    }

    /**
     * Import a CSV with a header row (see COLUMNS; title and url are required).
     *
     * @return array{saved: int, skipped: int}
     */
    public static function import(string $path): array
    {
        $handle = fopen($path, 'r');
        // Excel's "CSV UTF-8" starts with a byte-order mark; drop it from the first column name.
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), fgetcsv($handle) ?: []);
        $names = self::names();
        $saved = $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_intersect_key(array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null)) ?: [], array_flip(self::COLUMNS));
            self::save($data, $names) ? $saved++ : $skipped++;
        }
        fclose($handle);

        return compact('saved', 'skipped');
    }

    /**
     * Tag every story again, after the alias list changes. Adds to the stations a story has;
     * stations given in the CSV stay.
     */
    public static function retag(): int
    {
        $names = self::names();
        $count = 0;

        DB::table('archive_stories')->orderBy('id')->each(function ($story) use ($names, &$count) {
            $tags = self::tag($story->title.' '.$story->description, $names);
            if ($tags['stations']) {
                DB::table('archive_story_stations')->insertOrIgnore(array_map(fn ($stop) => ['archive_story_id' => $story->id, 'stop' => $stop], $tags['stations']));
                $count++;
            }
        });

        return $count;
    }
}
