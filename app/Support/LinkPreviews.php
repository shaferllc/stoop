<?php

namespace App\Support;

use App\Models\LinkPreview;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a title and description for a link card. Only public http(s) hosts on
 * ports 80/443 are fetched, and every redirect hop is checked again, so a post
 * can't make the server request internal addresses.
 */
class LinkPreviews
{
    public static function for(string $url): ?LinkPreview
    {
        $hash = hash('sha256', $url);

        if ($existing = LinkPreview::firstWhere('url_hash', $hash)) {
            return $existing;
        }

        $meta = self::fetch($url);

        return $meta ? LinkPreview::create(['url_hash' => $hash, 'url' => $url, ...$meta]) : null;
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string}|null
     */
    protected static function fetch(string $url): ?array
    {
        // ponytail: fetched inline with a short timeout; move to a queued job if posting feels slow
        try {
            for ($hop = 0; $hop < 3; $hop++) {
                if (! self::isPublic($url)) {
                    return null;
                }

                $response = Http::withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'StoopBot/1.0 (+link previews)', 'Accept' => 'text/html'])
                    ->timeout(4)->get($url);

                if ($response->redirect() && $location = $response->header('Location')) {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }

                if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'html')) {
                    return null;
                }

                return self::parse(substr($response->body(), 0, 512 * 1024));
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string}|null
     */
    public static function parse(string $html): ?array
    {
        $meta = function (string $name) use ($html): ?string {
            $q = preg_quote($name, '~');
            if (preg_match('~<meta[^>]+(?:property|name)=["\']'.$q.'["\'][^>]*content=["\']([^"\']*)~i', $html, $m)
                || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$q.'["\']~i', $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            }

            return null;
        };

        $title = $meta('og:title') ?? (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : null);

        if (! $title) {
            return null;
        }

        return [
            'title' => mb_substr($title, 0, 200),
            'description' => ($d = $meta('og:description') ?? $meta('description')) ? mb_substr($d, 0, 300) : null,
            'image' => $meta('og:image'),
        ];
    }

    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! $host || ! in_array($parts['port'] ?? 0, [0, 80, 443], true)) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return $ips !== [];
    }
}
