<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\User;
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
    // Article categories mapped to Metro lines. Anything else goes on the Blue (Culture) line.
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
            'name' => 'From the Magazine',
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
            if ($published($item)->lt(now()->subDays(2))) {
                continue;
            }

            $link = (string) ($item->link['href'] ?? $item->link);
            $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5));
            $guid = (string) ($item->guid ?? $item->id ?? $link);
            $category = (string) ($item->category['term'] ?? $item->category);

            if (! $link || ! $title || DB::table('articles')->where('guid_hash', hash('sha256', $guid))->exists()) {
                continue;
            }

            $dispatch = rescue(fn () => FileDispatch::handle(self::account(), [
                'body' => Str::limit($title, config('stoop.max_length') - 30, '…').' '.$link,
                'line' => self::lineFor($category.' '.$title),
            ], confirmed: true), null, false);

            DB::table('articles')->insert([
                'guid_hash' => hash('sha256', $guid),
                'url' => Str::limit($link, 500, ''),
                'title' => Str::limit($title, 300, ''),
                'category' => $category ? Str::limit($category, 80, '') : null,
                'dispatch_id' => $dispatch?->id,
                'published_at' => rescue(fn () => Carbon::parse((string) ($item->pubDate ?? $item->published ?? $item->updated)), now(), false),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $posted += $dispatch ? 1 : 0;
        }

        return $posted;
    }

    public static function lineFor(string $text): string
    {
        $text = strtolower($text);

        foreach (self::LINES as $line => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
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
