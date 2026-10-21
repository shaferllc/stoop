<?php

namespace App\Support;

use App\Models\Author;
use App\Models\Place;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * The magazine's back catalog from its WordPress REST API, as metadata only: title, link,
 * date, section, tags, bylines, the excerpt the API publishes (trimmed) and the featured
 * image on the magazine's own domain. Older articles go into the Archive, never the feed;
 * only Magazine::import posts new ones.
 *
 * What the site exposes (checked October 2026): /wp-json/wp/v2/posts, media, users, tags and
 * categories are public. The sitemap times out and category feeds redirect to newsletter
 * comment feeds, so the API is the source. Lists ("100 Very Best Restaurants") are the
 * `provider` post type, tagged with the list in class_list; ranks aren't exposed.
 *
 * Polite: obeys robots.txt, identifies as StoopBot, one request a second, backs off on 429
 * and 5xx, and caches responses while developing locally.
 */
class MagazineBackCatalog
{
    public const STATUS = 'archive_import';

    protected const PER_PAGE = 100;

    protected const FIELDS = 'id,date,link,title,excerpt,categories,tags,author,featured_media';

    protected static float $lastRequest = 0;

    public static function site(): ?string
    {
        $feed = (string) config('stoop.magazine_feed');
        $host = parse_url($feed, PHP_URL_HOST);

        return $host ? 'https://'.$host : null;
    }

    public static function userAgent(): string
    {
        return 'StoopBot/1.0 (+'.config('app.url').')';
    }

    /**
     * Import up to $max posts after the cursor (default: three years ago), a page at a time,
     * moving the cursor after each page so a stopped run picks up where it left off.
     *
     * @return array{imported: int, backfilled: int, done: bool, error: ?string}
     */
    public static function run(int $max = 0, ?string $since = null): array
    {
        $result = ['imported' => 0, 'backfilled' => 0, 'done' => false, 'error' => null];
        if (! self::site()) {
            return self::finish([...$result, 'error' => 'No magazine feed is set (STOOP_MAGAZINE_FEED).']);
        }

        return Cache::lock('stoop:archive-import', 3600)->get(function () use ($max, $since, $result) {
            $cursor = $since ? date('Y-m-d\T00:00:00', strtotime($since)) : self::status()['cursor'];

            for ($seen = 0; ! $max || $seen < $max; $seen += count($posts)) {
                $perPage = $max ? min(self::PER_PAGE, $max - $seen) : self::PER_PAGE;
                $posts = self::get('/wp-json/wp/v2/posts', [
                    'per_page' => $perPage, 'orderby' => 'date', 'order' => 'asc', 'after' => $cursor, '_fields' => self::FIELDS,
                ]);
                if (! is_array($posts)) {
                    $result['error'] = 'Couldn’t read the magazine’s posts.';
                    break;
                }

                $page = self::savePage($posts);
                $result['imported'] += $page['imported'];
                $result['backfilled'] += $page['backfilled'];

                // Saving is idempotent by URL, so back off a second in case two posts share one.
                if ($posts) {
                    $cursor = date('Y-m-d\\TH:i:s', strtotime(end($posts)['date']) - 1);
                }
                $result['done'] = count($posts) < $perPage;
                self::finish([...$result, 'cursor' => $cursor]);

                if ($result['done']) {
                    break;
                }
            }

            return self::finish([...$result, 'cursor' => $cursor]);
        }) ?: [...$result, 'error' => 'An import is already running.'];
    }

    /**
     * Where the importer is and how its last run went.
     *
     * @return array{cursor: string, ran_at: ?string, imported: int, backfilled: int, done: bool, error: ?string}
     */
    public static function status(): array
    {
        return [
            'cursor' => now(config('stoop.timezone'))->subYears(3)->format('Y-m-d\T00:00:00'),
            'ran_at' => null, 'imported' => 0, 'backfilled' => 0, 'done' => false, 'error' => null,
            ...(array) Settings::get(self::STATUS, []),
        ];
    }

    protected static function finish(array $result): array
    {
        Settings::set(self::STATUS, [...self::status(), ...$result, 'ran_at' => now()->toIso8601String()]);

        return $result;
    }

    /**
     * Save one page of posts, looking up its images, writers and tags in one request each.
     *
     * @param  array<int, array<string, mixed>>  $posts
     * @return array{imported: int, backfilled: int}
     */
    protected static function savePage(array $posts): array
    {
        $images = self::lookup('media', array_column($posts, 'featured_media'), 'id,source_url');
        $writers = self::lookup('users', array_column($posts, 'author'), 'id,name,slug,description,avatar_urls');
        $tags = self::lookup('tags', array_merge(...array_map(fn ($p) => (array) ($p['tags'] ?? []), $posts)), 'id,name');
        $categories = Cache::remember('stoop:archive-import:categories', now()->addDay(), fn () => collect(self::get('/wp-json/wp/v2/categories', ['per_page' => 100, '_fields' => 'id,name']) ?: [])
            ->mapWithKeys(fn ($c) => [$c['id'] => self::text($c['name'])])->all());
        $names = Archive::names();
        $counts = ['imported' => 0, 'backfilled' => 0];

        foreach ($posts as $post) {
            $url = strtok((string) ($post['link'] ?? ''), '?#');
            $title = self::text($post['title']['rendered'] ?? '');
            $sections = array_map(fn ($id) => strtolower($categories[$id] ?? ''), (array) ($post['categories'] ?? []));
            $section = $categories[$post['categories'][0] ?? 0] ?? null;
            if (! $url || $title === '' || array_intersect($sections, Magazine::SKIP_SECTIONS)) {
                continue;
            }

            $excerpt = Str::limit(trim(str_replace(['[…]', '[...]'], '', self::text($post['excerpt']['rendered'] ?? ''))), 299, '…') ?: null;
            $tagNames = array_values(array_filter(array_map(fn ($id) => $tags[$id]['name'] ?? null, (array) ($post['tags'] ?? []))));
            $image = self::ownImage($images[$post['featured_media'] ?? 0]['source_url'] ?? null);
            $found = Archive::tag($title.' '.$excerpt.' '.implode(' · ', $tagNames), $names);

            $id = Archive::save([
                'url' => $url, 'title' => $title, 'description' => $excerpt, 'date' => $post['date'] ?? null,
                'section' => $section, 'stations' => implode(',', $found['stations']), 'neighborhoods' => implode(', ', $found['neighborhoods']),
            ], $names);
            if (! $id) {
                continue;
            }

            DB::table('archive_stories')->where('id', $id)->update([
                'image_url' => $image,
                'line' => Magazine::SECTIONS[strtolower((string) $section)] ?? Magazine::lineFor($title.' '.$section),
                'tags' => $tagNames ? Str::limit(implode(', ', $tagNames), 300, '') : null,
            ]);

            $authors = array_values(array_filter([self::writer($writers[$post['author'] ?? 0] ?? null)]));
            foreach ($authors as $i => $author) {
                DB::table('archive_story_author')->insertOrIgnore(['archive_story_id' => $id, 'author_id' => $author->id, 'position' => $i]);
            }
            $counts['imported']++;
            $counts['backfilled'] += self::backfillArticle($url, $image, $authors);
        }

        return $counts;
    }

    /**
     * Fill in the image and writers of an article the RSS import already posted, when missing.
     * Feed links carry tracking parameters, so match on the URL without them.
     *
     * @param  array<int, Author>  $authors
     */
    protected static function backfillArticle(string $url, ?string $image, array $authors): int
    {
        $article = DB::table('articles')->where(fn ($q) => $q->where('url', $url)->orWhere('url', 'like', $url.'?%'))->first();
        if (! $article) {
            return 0;
        }

        $changed = 0;
        if ($image && ! $article->image_url) {
            $changed = DB::table('articles')->where('id', $article->id)->update(['image_url' => $image]);
        }
        if ($authors && ! DB::table('article_author')->where('article_id', $article->id)->exists()) {
            $changed += count(Magazine::credit($article->id, array_map(fn ($a) => $a->name, $authors)));
        }

        return $changed ? 1 : 0;
    }

    /**
     * The Author for a WordPress user, with their bio and photo filled in when staff haven't set one.
     *
     * @param  array<string, mixed>|null  $user
     */
    protected static function writer(?array $user): ?Author
    {
        if (! $user || ! $author = Author::forName(self::text($user['name'] ?? ''))) {
            return null;
        }

        $bio = Str::limit(self::text($user['description'] ?? ''), 299, '…');
        $photo = self::ownImage(collect($user['avatar_urls'] ?? [])->last());
        $fill = array_filter([
            'bio' => ! $author->bio && $bio !== '' ? $bio : null,
            'photo_url' => ! $author->photo_url ? $photo : null,
        ]);
        $fill && DB::table('authors')->where('id', $author->id)->update($fill);

        return $author;
    }

    /**
     * Look up a page's worth of media, users or tags by ID in one request.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    protected static function lookup(string $type, array $ids, string $fields): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return [];
        }

        $rows = self::get("/wp-json/wp/v2/$type", ['include' => implode(',', $ids), 'per_page' => 100, '_fields' => $fields]);

        return collect(is_array($rows) ? $rows : [])->keyBy('id')->all();
    }

    /**
     * Import the magazine's lists (the `provider` entries in Food) into places: name, list and
     * link only. Keeps each list's latest edition from the last three years. Ranks and addresses
     * aren't in the API, so those stay empty, and places without a station show up in the admin.
     *
     * @param  array<int, string>  $only  list slugs, e.g. "100-very-best-restaurants-2026"
     * @return array{created: int, updated: int, lists: array<string, int>, error: ?string}
     */
    public static function importLists(array $only = []): array
    {
        $result = ['created' => 0, 'updated' => 0, 'lists' => [], 'error' => null];
        $entries = [];

        for ($page = 1; $page <= 50; $page++) {
            $rows = self::get('/wp-json/wp/v2/provider', ['categories' => self::foodCategory(), 'per_page' => 100, 'page' => $page, '_fields' => 'id,link,title,class_list']);
            if (! is_array($rows)) {
                $result['error'] = $page === 1 ? 'Couldn’t read the magazine’s lists.' : null;
                break;
            }
            array_push($entries, ...$rows);
            if (count($rows) < 100) {
                break;
            }
        }

        $lists = self::listsIn($entries, $only);
        $names = Archive::names();

        foreach ($entries as $entry) {
            $classes = (array) ($entry['class_list'] ?? []);
            $hoods = collect($classes)->filter(fn ($c) => str_starts_with($c, 'locations-'))
                ->map(fn ($c) => Str::headline(substr($c, 10)))->reject(fn ($h) => in_array(strtolower($h), ['dc', 'md', 'va'], true))->values();
            $stop = $hoods->map(fn ($h) => Archive::tag($h, $names)['stations'][0] ?? null)->filter()->first();

            foreach ($classes as $class) {
                $slug = preg_replace('/^(provider_type|awards)-/', '', $class);
                if (! isset($lists[$slug]) || $slug === $class) {
                    continue;
                }
                $outcome = Place::fromList([
                    'name' => Str::limit(self::text($entry['title']['rendered'] ?? ''), 120, ''),
                    'list_name' => $lists[$slug],
                    'category' => 'Restaurant',
                    'neighborhood' => $hoods->first() ? Str::limit($hoods->first(), 60, '') : null,
                    'stop' => $stop,
                    'article_url' => str_starts_with((string) ($entry['link'] ?? ''), self::site().'/') ? Str::limit($entry['link'], 300, '') : null,
                ]);
                if ($outcome) {
                    $result[$outcome]++;
                    $result['lists'][$lists[$slug]] = ($result['lists'][$lists[$slug]] ?? 0) + 1;
                }
            }
        }

        return $result;
    }

    /**
     * List slugs to import mapped to their display names. By default the newest edition of
     * each list page ("provider_type-100-very-best-restaurants-2026" over "-2025") from the
     * last three years; $only picks exact slugs instead, including awards like "eat-great-cheap-2019".
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $only
     * @return array<string, string>
     */
    protected static function listsIn(array $entries, array $only): array
    {
        $latest = [];
        foreach ($entries as $entry) {
            foreach ((array) ($entry['class_list'] ?? []) as $class) {
                $slug = (string) preg_replace('/^(provider_type|awards)-/', '', $class);
                if ($only) {
                    in_array($slug, $only, true) && $latest[$slug] = $slug;
                } elseif (preg_match('/^provider_type-(.+)-(\d{4})$/', $class, $m) && (int) $m[2] >= now()->year - 2 && $slug > ($latest[$m[1]] ?? '')) {
                    $latest[$m[1]] = $slug;
                }
            }
        }

        return collect($latest)->mapWithKeys(fn ($slug) => [$slug => Str::limit(Str::headline($slug), 120, '')])->all();
    }

    protected static function foodCategory(): int
    {
        return (int) (collect(self::get('/wp-json/wp/v2/categories', ['slug' => 'food', '_fields' => 'id']) ?: [])->first()['id'] ?? 0) ?: 4;
    }

    /**
     * Fill in neighborhood, cuisine, address, website and the nearest station for list places,
     * read from each place's page on the magazine's site (one polite request each). Only empty
     * fields are filled, and no review text is stored.
     *
     * @return array{checked: int, stations: int}
     */
    public static function enrichPlaces(): array
    {
        $host = parse_url(self::site(), PHP_URL_HOST);
        $checked = $stations = 0;

        Place::whereNotNull('list_name')->whereNull('stop')->where('article_url', 'like', '%'.$host.'/%')->each(function (Place $place) use (&$checked, &$stations) {
            $path = parse_url($place->article_url, PHP_URL_PATH) ?: '/';
            if (! self::allowed($path)) {
                return;
            }
            $html = Cache::remember('stoop:wp:page:'.md5($path), now()->addWeek(), fn () => self::request(self::site().$path)?->body() ?? '');
            $details = self::providerDetails($html);
            $checked++;

            $stop = self::stationFor(trim(($details['neighborhood'] ?? $place->neighborhood ?? '').' '.($details['address'] ?? '')));
            $place->forceFill(array_filter([
                'neighborhood' => $place->neighborhood ?: $details['neighborhood'],
                'category' => in_array($place->category, [null, '', 'Restaurant'], true) && $details['cuisine'] ? $details['cuisine'] : null,
                'address' => $place->address ?: $details['address'],
                'website' => $place->website ?: $details['website'],
                'stop' => $stop,
            ]))->save();
            $stations += $place->stop ? 1 : 0;
        });

        return ['checked' => $checked, 'stations' => $stations];
    }

    /**
     * The station for the first location listed that we recognize, using its most specific name
     * ("North Bethesda" beats "Bethesda"). "Bethesda, Navy Yard, Shaw" is Bethesda.
     */
    public static function stationFor(string $text): ?string
    {
        $names = Archive::names();
        foreach (array_filter(array_map('trim', explode(',', $text))) as $part) {
            $best = null;
            foreach ($names as $name => $codes) {
                if (Archive::tag($part, [$name => $codes])['stations'] && (! $best || mb_strlen($name) > mb_strlen($best[0]))) {
                    $best = [$name, $codes[0]];
                }
            }
            if ($best) {
                return $best[1];
            }
        }

        return null;
    }

    /**
     * The facts block on a provider page: "Navy Yard • Palestinian • $$$", then Locations and Website.
     *
     * @return array{neighborhood: ?string, cuisine: ?string, address: ?string, website: ?string}
     */
    public static function providerDetails(string $html): array
    {
        $text = html_entity_decode(preg_replace('/<[^>]+>/', "\n", preg_replace('/<(script|style)\b.*?<\/\1>/si', '', $html)), ENT_QUOTES | ENT_HTML5);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $out = ['neighborhood' => null, 'cuisine' => null, 'address' => null, 'website' => null];

        $dot = array_search('•', $lines, true);
        if ($dot !== false && $dot > 0) {
            $out['neighborhood'] = Str::limit($lines[$dot - 1], 60, '');
            $out['cuisine'] = isset($lines[$dot + 1]) && $lines[$dot + 1] !== '•' ? Str::limit($lines[$dot + 1], 60, '') : null;
        }
        if (($at = array_search('Locations', $lines, true)) !== false) {
            $parts = [];
            for ($i = $at + 1; $i < count($lines) && $i <= $at + 6 && $lines[$i] !== 'Website'; $i++) {
                $parts[] = rtrim($lines[$i], ',');
            }
            $out['address'] = $parts ? Str::limit(implode(', ', $parts), 200, '') : null;
        }
        if (($at = array_search('Website', $lines, true)) !== false && preg_match('~^https?://\S+$~', $lines[$at + 1] ?? '')) {
            $out['website'] = Str::limit($lines[$at + 1], 255, '');
        }

        return $out;
    }

    /**
     * A JSON GET on the magazine's site, or null if robots.txt disallows it or it keeps failing.
     *
     * @param  array<string, mixed>  $query
     */
    public static function get(string $path, array $query = []): mixed
    {
        if (! self::allowed($path)) {
            return null;
        }
        $url = self::site().$path.($query ? '?'.http_build_query($query) : '');

        $fetch = function () use ($url) {
            $response = self::request($url);

            return $response?->successful() ? $response->json() : null;
        };

        // Cache while developing, so trying things out doesn't fetch the same pages again.
        return app()->isLocal() ? Cache::remember('stoop:wp:'.md5($url), now()->addDay(), $fetch) : $fetch();
    }

    /**
     * One throttled request, retried with backoff on 429 and 5xx (honoring Retry-After).
     */
    protected static function request(string $url): ?Response
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $wait = self::$lastRequest + 1 - microtime(true);
            if ($wait > 0) {
                Sleep::usleep((int) ($wait * 1_000_000));
            }
            self::$lastRequest = microtime(true);

            $response = rescue(fn () => Http::withUserAgent(self::userAgent())->timeout(30)->acceptJson()->get($url), null, false);
            if ($response && $response->status() !== 429 && $response->status() < 500) {
                return $response;
            }
            $after = $response?->header('Retry-After');
            Sleep::sleep(min(60, is_numeric($after) ? max(1, (int) $after) : 2 ** ($attempt + 1)));
        }

        return null;
    }

    /**
     * Whether robots.txt lets StoopBot fetch a path: the longest matching Allow or Disallow
     * rule in the StoopBot group (or "*" when there's none) wins.
     */
    public static function allowed(string $path): bool
    {
        $rules = Cache::get($key = 'stoop:robots:'.self::site());
        if ($rules === null) {
            // A missing robots.txt (4xx) allows everything; an unreachable one (5xx) allows nothing, and isn't cached.
            $response = self::request(self::site().'/robots.txt');
            if (! $response) {
                return false;
            }
            $rules = $response->status() >= 400 ? [] : self::robotRules($response->body());
            Cache::put($key, $rules, now()->addDay());
        }

        $best = [true, -1];
        foreach ($rules as [$allow, $prefix]) {
            if ($prefix !== '' && str_starts_with($path, $prefix) && strlen($prefix) > $best[1]) {
                $best = [$allow, strlen($prefix)];
            }
        }

        return $best[0];
    }

    /**
     * Allow and Disallow rules for StoopBot, or for "*" when robots.txt has no StoopBot group.
     *
     * @return list<array{0: bool, 1: string}>
     */
    protected static function robotRules(string $body): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (! preg_match('/^\s*([a-z-]+)\s*:\s*(.*?)\s*(?:#.*)?$/i', $line, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            if ($field === 'user-agent') {
                $agents = $inRules ? [] : $agents;
                $agents[] = strtolower($m[2]);
                $inRules = false;
            } elseif (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field === 'allow', $m[2]];
                }
            }
        }

        return $groups['stoopbot'] ?? $groups['*'] ?? [];
    }

    /**
     * Only https images on the magazine's own domain, so readers' browsers never call anyone else.
     */
    protected static function ownImage(?string $url): ?string
    {
        $domain = fn ($host) => implode('.', array_slice(explode('.', (string) $host), -2));

        return $url && str_starts_with($url, 'https://') && mb_strlen($url) <= 500
            && $domain(parse_url($url, PHP_URL_HOST)) === $domain(parse_url((string) self::site(), PHP_URL_HOST)) ? $url : null;
    }

    protected static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }
}
