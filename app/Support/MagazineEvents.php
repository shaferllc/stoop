<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\Dispatch;
use App\Models\Place;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * The magazine's things-to-do listings as Happenings posted by @magazine.
 *
 * The site has no events feed, iCal or Event JSON-LD (checked October 2026), so this reads
 * the weekly "Things to Do" roundups in the article feed: each <li> is an event, named in
 * <strong> and ending "(Sat, $35, Shaw)". The day is resolved against the roundup's week.
 * Anything without a readable date is skipped. Editors can upload a CSV instead.
 */
class MagazineEvents
{
    /** Listings rarely give a time, so undated-time events go on the calendar at noon. */
    public const DEFAULT_HOUR = 12;

    protected const STATUS = 'stoop:magazine-events:status';

    protected const DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /**
     * Fetch the article feed and post or update the events in its things-to-do roundups.
     *
     * @return array{posted: int, updated: int, skipped: int, error: ?string}
     */
    public static function import(): array
    {
        $result = ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => null];

        if (! $url = config('stoop.magazine_feed')) {
            return self::finish([...$result, 'error' => 'No magazine feed is set (STOOP_MAGAZINE_FEED).']);
        }

        try {
            $xml = new SimpleXMLElement(Http::timeout(10)->get($url)->throw()->body(), LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable $e) {
            return self::finish([...$result, 'error' => 'Couldn’t read the feed: '.Str::limit($e->getMessage(), 120)]);
        }

        return Cache::lock('stoop:magazine-events', 300)->get(function () use ($xml, $result) {
            foreach ($xml->channel->item ?? [] as $item) {
                // Not collect(): an item's <category> elements share one key, so only the last would survive.
                $categories = [];
                foreach ($item->category as $c) {
                    $categories[] = strtolower(trim((string) $c));
                }
                if (! in_array('things to do', $categories, true)) {
                    continue;
                }

                $link = strtok((string) $item->link, '?');
                $published = rescue(fn () => CarbonImmutable::parse((string) $item->pubDate), now()->toImmutable(), false);
                $html = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;

                foreach (self::parse($html, $published) as $event) {
                    $result[self::save([...$event, 'url' => $link, 'source' => 'feed'])]++;
                }
            }

            return self::finish($result);
        }) ?: [...$result, 'error' => 'An import is already running.'];
    }

    /**
     * Events from an uploaded CSV with a header row: title, date (YYYY-MM-DD), time (optional,
     * e.g. 7:30pm), venue, url, description. Times are DC time.
     *
     * @return array{posted: int, updated: int, skipped: int, error: ?string}
     */
    public static function importCsv(string $path): array
    {
        $result = ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => null];
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '') ?: []);

        if (! in_array('title', $header, true) || ! in_array('date', $header, true) || ! in_array('url', $header, true)) {
            fclose($handle);

            return self::finish([...$result, 'error' => 'The CSV needs title, date and url columns.']);
        }

        $tz = config('stoop.timezone');
        for ($n = 0; ($row = fgetcsv($handle, escape: '')) !== false && $n < 500; $n++) {
            $row = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            $startsAt = rescue(fn () => CarbonImmutable::parse(trim($row['date']).' '.(trim($row['time'] ?? '') ?: self::DEFAULT_HOUR.':00'), $tz), null, false);
            $url = trim($row['url']);

            if (! $startsAt || trim($row['title']) === '' || ! str_starts_with($url, 'https://') || mb_strlen($url) > 500) {
                $result['skipped']++;

                continue;
            }

            $result[self::save([
                'title' => trim($row['title']),
                'text' => trim($row['title'].'. '.($row['description'] ?? '')),
                'venue' => trim($row['venue'] ?? ''),
                'starts_at' => $startsAt,
                'timed' => trim($row['time'] ?? '') !== '',
                'url' => $url,
                'source' => 'csv',
            ])]++;
        }
        fclose($handle);

        return self::finish($result);
    }

    /**
     * Events in a roundup's HTML. Each needs a name and a day; the week comes from the
     * roundup's date heading ("October 19–October 25") or, failing that, its publish date.
     *
     * @return array<int, array{title: string, text: string, venue: string, starts_at: CarbonImmutable, timed: bool}>
     */
    public static function parse(string $html, \DateTimeInterface $published): array
    {
        if (trim($html) === '') {
            return [];
        }

        $tz = config('stoop.timezone');
        $published = CarbonImmutable::instance($published)->timezone($tz);
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $weekStart = preg_match("/\b($months)\s+(\d{1,2})\s*[–—-]/u", $text, $m)
            ? CarbonImmutable::parse("$m[1] $m[2] ".$published->year, $tz)
            : $published->startOfDay();
        // A roundup published in late December for the first week of January.
        if ($weekStart->lt($published->subMonths(2))) {
            $weekStart = $weekStart->addYear();
        }

        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NONET);
        $events = [];

        foreach ((new DOMXPath($doc))->query('//li') as $li) {
            /** @var DOMElement $li */
            $line = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
            $strong = $li->getElementsByTagName('strong')->item(0) ?? $li->getElementsByTagName('a')->item(0);
            $title = trim((string) preg_replace('/\s+/u', ' ', $strong?->textContent ?? ''), " .:\u{A0}");

            // The trailing note: "(Sat, $35, Shaw)" or "(Oct. 24, free, Wharf)".
            if ($title === '' || ! preg_match('/\(([^()]{2,120})\)\s*\.?$/u', $line, $note)) {
                continue;
            }
            $parts = array_map('trim', explode(',', $note[1]));

            $day = null;
            if (preg_match('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+(\d{1,2})\b/i', $note[1], $d)) {
                $day = rescue(fn () => CarbonImmutable::parse(substr($d[1], 0, 3)." $d[2] ".$weekStart->year, $tz), null, false);
                $day = $day?->lt($weekStart->subMonths(2)) ? $day->addYear() : $day;
            } elseif (preg_match('/^(sun|mon|tue|wed|thu|fri|sat)/i', $parts[0], $d)) {
                // "Fri–Sun" starts Friday: the first matching day on or after the week's start.
                $day = $weekStart->addDays((self::DAYS[strtolower($d[1])] - $weekStart->dayOfWeek + 7) % 7);
            }

            if (! $day) {
                continue;
            }

            $timed = (bool) preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s?m\b\.?/i', $note[1], $t);
            $hour = $timed ? ((int) $t[1] % 12) + (strtolower($t[3]) === 'p' ? 12 : 0) : self::DEFAULT_HOUR;

            $events[] = [
                'title' => Str::limit($title, 200, ''),
                'text' => $line,
                // The last part that isn't the day, a price or a time is the neighborhood or venue.
                'venue' => collect(array_slice($parts, 1))->reject(fn ($p) => preg_match('/\$|\bfree\b|\d\s*[ap]\.?m|registration|tickets?/i', $p))->last() ?? '',
                'starts_at' => $day->setTime($hour, $timed ? (int) ($t[2] ?: 0) : 0),
                'timed' => $timed,
            ];
        }

        return $events;
    }

    /**
     * The station for an event: a known place named in the listing, else a station named in
     * the venue or the text. Null when nothing matches.
     */
    public static function stationFor(string $text, string $venue = ''): ?string
    {
        $haystack = mb_strtolower($text.' '.$venue);

        // ponytail: scans every place with a stop; index place names if the list grows past a few thousand
        $place = Place::whereNotNull('stop')->get(['name', 'stop'])
            ->filter(fn (Place $p) => mb_strlen($p->name) >= 4 && preg_match('/\b'.preg_quote(mb_strtolower($p->name), '/').'\b/u', $haystack))
            ->sortByDesc(fn (Place $p) => mb_strlen($p->name))->first();

        return $place?->stop ?? ($venue !== '' ? Stations::find($venue) : null) ?? Stations::find($text);
    }

    /**
     * Post a new event, or move an existing one's start time. Past events are skipped.
     *
     * @param  array{title: string, text: string, venue: string, starts_at: CarbonImmutable, timed: bool, url: string, source: string}  $event
     * @return 'posted'|'updated'|'skipped'
     */
    public static function save(array $event): string
    {
        $startsAt = $event['starts_at']->utc();
        $key = Str::limit(Str::slug($event['title']), 120, '');

        if ($key === '' || $startsAt->lt(now()->startOfDay())) {
            return 'skipped';
        }

        // The same event this past week or later is the same listing (perhaps moved); an older
        // match is last year's edition.
        $existing = DB::table('magazine_events')->where('key', $key)->where('starts_at', '>=', now()->subWeek())->latest('id')->first();
        $when = self::when($event['starts_at'], $event['timed']);

        if ($existing) {
            if ($existing->starts_at === $startsAt->toDateTimeString() || ! $existing->dispatch_id || ! $dispatch = Dispatch::find($existing->dispatch_id)) {
                return 'skipped';
            }
            $dispatch->happening()->updateOrCreate([], ['starts_at' => $startsAt]);
            $dispatch->forceFill(['happening_at' => $when])->save();
            DB::table('magazine_events')->where('id', $existing->id)->update(['starts_at' => $startsAt, 'updated_at' => now()]);

            return 'updated';
        }

        $account = Magazine::account();
        $label = Str::start((string) config('stoop.magazine_name'), 'From ');
        $suffix = "\n\n$label: ".$event['url'];
        $text = $event['text'] !== '' ? $event['text'] : $event['title'];

        try {
            // A roundup has two dozen events; the per-minute posting limit is for people.
            RateLimiter::clear('dispatch:'.$account->id);
            $dispatch = FileDispatch::handle($account, [
                'type' => 'happening',
                // Str::limit adds the ellipsis after the limit, hence the - 1.
                'body' => Str::limit($text, config('stoop.max_length') - mb_strlen($suffix) - 1, '…').$suffix,
                'line' => self::lineFor($text),
                'stop' => self::stationFor($text, $event['venue']),
                'happeningAt' => $when,
                'happeningStarts' => $event['starts_at']->timezone(config('stoop.timezone'))->format('Y-m-d H:i'),
            ], confirmed: true);
        } catch (Throwable $e) {
            report($e);

            return 'skipped';
        }

        $dispatch->forceFill(['details' => [...($dispatch->details ?? []), 'magazine_event' => ['source' => $label, 'url' => $event['url']]]])->save();
        DB::table('magazine_events')->insert([
            'key' => $key,
            'title' => Str::limit($event['title'], 200, ''),
            'url' => Str::limit($event['url'], 500, ''),
            'dispatch_id' => $dispatch->id,
            'starts_at' => $startsAt,
            'source' => $event['source'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'posted';
    }

    /**
     * The magazine's keyword lines (food is Red, Maryland and Virginia are Silver), else the
     * "things to do" section's line.
     */
    public static function lineFor(string $text): string
    {
        $line = Magazine::lineFor($text);

        return $line === 'blue' ? Magazine::SECTIONS['things to do'] : $line;
    }

    /**
     * The free-text "when" shown on the card. No made-up time when the listing has none.
     */
    protected static function when(CarbonImmutable $startsAt, bool $timed): string
    {
        return $startsAt->timezone(config('stoop.timezone'))->format($timed ? 'D M j · g:ia' : 'D M j');
    }

    /**
     * @return array{at: ?string, posted: int, updated: int, skipped: int, error: ?string}|null
     */
    public static function status(): ?array
    {
        return Cache::get(self::STATUS);
    }

    /**
     * @param  array{posted: int, updated: int, skipped: int, error: ?string}  $result
     * @return array{posted: int, updated: int, skipped: int, error: ?string}
     */
    protected static function finish(array $result): array
    {
        Cache::forever(self::STATUS, ['at' => now()->toIso8601String(), ...$result]);

        return $result;
    }
}
