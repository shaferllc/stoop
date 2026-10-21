<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\Author;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\MagazineActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Posts new magazine articles to Stoop from the site's RSS or Atom feed, so there's
 * fresh city news in the feed every day.
 */
class Magazine
{
    // The site's section (its first category) mapped to a Metro line.
    public const SECTIONS = [
        'food' => 'red',
        'things to do' => 'orange',
        'arts & entertainment' => 'orange',
        'home & real estate' => 'green',
        'real estate' => 'green',
        'home' => 'green',
        'news & politics' => 'blue',
        'weddings' => 'blue',
        'health' => 'blue',
        'style' => 'blue',
        'sports' => 'orange',
    ];

    // Paid content is never posted as editorial.
    public const SKIP_SECTIONS = ['sponsored', 'sponsored content', 'partner content'];

    // Fallback keywords, when the section isn't one of the above. Anything else goes on the Blue (Culture) line.
    protected const LINES = [
        'red' => ['food', 'restaurant', 'dining', 'eat', 'drink', 'bar', 'coffee'],
        'orange' => ['things to do', 'going out', 'nightlife', 'music', 'event', 'weekend'],
        'green' => ['real estate', 'home', 'house', 'neighborhood', 'rent'],
        'silver' => ['maryland', 'virginia', 'suburb', 'arlington', 'alexandria', 'bethesda'],
    ];

    /**
     * The staff account articles post from.
     */
    public static function account(): User
    {
        $username = config('stoop.magazine_account');

        if ($user = User::where('username', $username)->first()) {
            return $user;
        }

        $user = new User;
        $user->forceFill([
            'username' => $username,
            'name' => config('stoop.magazine_name'),
            'email' => $username.'-desk@stoop.invalid',
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'accepted_rules_at' => now(),
            'is_staff' => true,
        ])->save();

        return $user;
    }

    /**
     * Fetch the feed and post anything new. Returns how many articles were posted.
     */
    public static function import(): int
    {
        if (! $url = config('stoop.magazine_feed')) {
            return 0;
        }

        try {
            $xml = new SimpleXMLElement(Http::timeout(10)->get($url)->throw()->body(), LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            return 0;
        }

        $items = [];
        foreach ($xml->channel->item ?? $xml->entry ?? [] as $item) {
            $items[] = $item;
        }

        // Feeds list newest first; post oldest first so the feed reads in order, and skip
        // anything more than two days old (the first import would otherwise flood the feed).
        $published = fn ($item) => rescue(fn () => Carbon::parse((string) ($item->pubDate ?? $item->published ?? $item->updated)), now(), false);
        usort($items, fn ($a, $b) => $published($a) <=> $published($b));
        $posted = 0;

        foreach ($items as $item) {
            $link = (string) ($item->link['href'] ?? $item->link);
            $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5));
            $guid = (string) ($item->guid ?? $item->id ?? $link);
            $category = trim((string) ($item->category['term'] ?? $item->category));
            $summary = trim(html_entity_decode(strip_tags((string) ($item->description ?? $item->summary)), ENT_QUOTES | ENT_HTML5));
            $hash = self::contentHash($item);

            // Already posted: if the article changed since, tell the thread. Articles imported
            // before hashes were stored just get one, without an update.
            if ($article = DB::table('articles')->where('guid_hash', hash('sha256', $guid))->first()) {
                if ($article->content_hash !== $hash) {
                    DB::table('articles')->where('id', $article->id)->update(['content_hash' => $hash, 'updated_at' => now()]);
                    if ($article->content_hash !== null) {
                        ArticleUpdates::post($article, 'The magazine updated this story since it was posted. '.$link, $hash);
                    }
                }

                continue;
            }

            if ($published($item)->lt(now()->subDays(2))
                || ! $link || ! $title || in_array(strtolower($category), self::SKIP_SECTIONS, true)) {
                continue;
            }

            // Tag the article with the stations it mentions. The post itself gets no stop, so
            // @magazine never earns mayor or rivalry points.
            $stations = Archive::tag($title.' '.$summary)['stations'];

            $dispatch = rescue(fn () => FileDispatch::handle(self::account(), [
                'body' => Str::limit($title, config('stoop.max_length') - 30, '…').' '.$link,
                'line' => self::SECTIONS[strtolower($category)] ?? self::lineFor($title),
            ], confirmed: true), null, false);

            // Shown as an article card in the feed (see the dispatch card).
            $image = self::imageFor($item, $url);
            $dispatch?->forceFill(['details' => [...($dispatch->details ?? []), 'article' => array_filter([
                'title' => Str::limit($title, 300, ''), 'section' => $category ? Str::limit($category, 80, '') : null, 'image' => $image,
            ])]])->save();

            $articleId = DB::table('articles')->insertGetId([
                'image_url' => $image,
                'guid_hash' => hash('sha256', $guid),
                'url' => Str::limit($link, 500, ''),
                'title' => Str::limit($title, 300, ''),
                'category' => $category ? Str::limit($category, 80, '') : null,
                'dispatch_id' => $dispatch?->id,
                'published_at' => rescue(fn () => Carbon::parse((string) ($item->pubDate ?? $item->published ?? $item->updated)), now(), false),
                'content_hash' => $hash,
                'stations' => $stations ? Str::limit(implode(',', $stations), 60, '') : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $authors = self::credit($articleId, self::bylines($item));
            if ($dispatch) {
                self::notifyFollowers($authors, $dispatch);
            }

            $posted += $dispatch ? 1 : 0;
        }

        return $posted;
    }

    /**
     * Byline names from an RSS item (one or more dc:creator, each possibly "A and B")
     * or an Atom entry (author/name).
     *
     * @return array<int, string>
     */
    public static function bylines(SimpleXMLElement $item): array
    {
        $names = [];
        foreach ($item->children('http://purl.org/dc/elements/1.1/')->creator as $creator) {
            $names[] = (string) $creator;
        }
        foreach ($item->author ?? [] as $author) {
            $names[] = (string) ($author->name ?? '') ?: (string) $author;
        }

        return self::names($names);
    }

    /**
     * Clean byline strings into separate names: splits "A, B and C", drops "By " and email addresses.
     *
     * @param  array<int, string>  $raw
     * @return array<int, string>
     */
    public static function names(array $raw): array
    {
        return collect($raw)
            ->flatMap(fn ($name) => preg_split('/\s*(?:,|&|\band\b)\s*/i', html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5)))
            ->map(fn ($name) => trim((string) preg_replace('/^by\s+/i', '', trim($name))))
            // RSS <author> is often an email address; never show one.
            ->filter(fn ($name) => $name !== '' && ! str_contains($name, '@') && mb_strlen($name) <= 120)
            ->unique(fn ($name) => Str::slug($name))->values()->all();
    }

    /**
     * Attach writers to an article, creating any we haven't seen. Returns the writers.
     *
     * @param  array<int, string>  $names
     * @return array<int, Author>
     */
    public static function credit(int $articleId, array $names): array
    {
        $authors = array_values(array_filter(array_map(fn ($name) => Author::forName($name), $names)));
        foreach ($authors as $i => $author) {
            DB::table('article_author')->insertOrIgnore(['article_id' => $articleId, 'author_id' => $author->id, 'position' => $i]);
        }

        return $authors;
    }

    /**
     * Tell readers who follow a writer that their new article is up (once each, even with co-authors).
     *
     * @param  array<int, Author>  $authors
     */
    protected static function notifyFollowers(array $authors, Dispatch $dispatch): void
    {
        $notified = [];
        foreach ($authors as $author) {
            $author->followers()->whereNotIn('users.id', $notified)->each(function (User $user) use ($author, $dispatch, &$notified) {
                $user->notify(new MagazineActivity('author', $dispatch->user, $dispatch, ['author' => $author->name, 'author_slug' => $author->slug]));
                $notified[] = $user->id;
            });
        }
    }

    /**
     * Credit writers on articles imported before bylines were stored: first from the current
     * feed, then from each remaining article page's author meta tag. Nobody is notified.
     */
    public static function backfillAuthors(): int
    {
        $missing = fn () => DB::table('articles')->whereNotExists(fn ($q) => $q->from('article_author')->whereColumn('article_author.article_id', 'articles.id'));
        $credited = 0;

        if ($url = config('stoop.magazine_feed')) {
            $xml = rescue(fn () => new SimpleXMLElement(Http::timeout(10)->get($url)->throw()->body(), LIBXML_NONET | LIBXML_NOCDATA), null, false);
            foreach ($xml ? ($xml->channel->item ?? $xml->entry ?? []) : [] as $item) {
                $guid = (string) ($item->guid ?? $item->id ?? ($item->link['href'] ?? $item->link));
                $id = $missing()->where('guid_hash', hash('sha256', $guid))->value('id');
                $credited += $id && self::credit($id, self::bylines($item)) ? 1 : 0;
            }
        }

        foreach ($missing()->get(['id', 'url']) as $article) {
            $html = rescue(fn () => Http::timeout(10)->get($article->url)->throw()->body(), '', false);
            // ponytail: one regex for <meta name="author">; read JSON-LD if the site drops the tag
            if (preg_match('/<meta[^>]+name=["\']author["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $credited += self::credit($article->id, self::names([$m[1]])) ? 1 : 0;
            }
        }

        return $credited;
    }

    /**
     * Visible dispatches linking to an article, plus the replies under them: the conversation
     * the discussion embed counts.
     *
     * @return Builder<Dispatch>
     */
    public static function discussion(string $url): Builder
    {
        $ids = Dispatch::visibleTo(null)->whereHas('linkPreview', fn ($q) => $q->where('url_hash', hash('sha256', $url)))->pluck('id');

        return Dispatch::visibleTo(null)->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('parent_id', $ids));
    }

    /**
     * The article's lead image from an RSS enclosure or Media RSS tag. Only https images on
     * the magazine's own domain, so a feed can't make readers' browsers call anyone else.
     */
    protected static function imageFor(SimpleXMLElement $item, string $feed): ?string
    {
        $media = $item->children('http://search.yahoo.com/mrss/');
        $candidates = [
            str_starts_with((string) ($item->enclosure['type'] ?? ''), 'image/') ? (string) $item->enclosure['url'] : '',
            (string) ($media->content?->attributes()['url'] ?? ''),
            (string) ($media->thumbnail?->attributes()['url'] ?? ''),
        ];
        $domain = fn (?string $host) => implode('.', array_slice(explode('.', (string) $host), -2));

        foreach ($candidates as $url) {
            if (str_starts_with($url, 'https://') && mb_strlen($url) <= 500 && $domain(parse_url($url, PHP_URL_HOST)) === $domain(parse_url($feed, PHP_URL_HOST))) {
                return $url;
            }
        }

        return null;
    }

    /**
     * A fingerprint of what readers see in an item: title, summary, full content and the
     * feed's updated date. It changes when the article is corrected or updated.
     */
    protected static function contentHash(SimpleXMLElement $item): string
    {
        return hash('sha256', implode("\n", [
            (string) $item->title,
            (string) ($item->description ?? $item->summary),
            (string) ($item->children('content', true)->encoded ?? $item->content),
            (string) $item->updated,
        ]));
    }

    public static function lineFor(string $text): string
    {
        $text = strtolower($text);

        foreach (self::LINES as $line => $words) {
            foreach ($words as $word) {
                // Whole words only, so "Beater" isn't food.
                if (preg_match('/\b'.preg_quote($word, '/').'\b/', $text)) {
                    return $line;
                }
            }
        }

        return 'blue';
    }

    /**
     * Apply a subscription change from the magazine's subscription system.
     */
    public static function setSubscription(string $email, ?string $until): void
    {
        $hash = User::hashSubject($email);
        $until = $until ? Carbon::parse($until) : null;

        DB::table('subscribers')->updateOrInsert(['email_hash' => $hash], ['subscribed_until' => $until, 'updated_at' => now(), 'created_at' => now()]);
        User::whereRaw('lower(email) = ?', [strtolower(trim($email))])->each(fn (User $u) => $u->forceFill(['subscribed_until' => $until])->save());
    }
}
