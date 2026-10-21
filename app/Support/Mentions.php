<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * @mentions and links inside a dispatch body.
 */
class Mentions
{
    // Not preceded by a word character, dot or @, so emails and scoped packages don't count.
    public const PATTERN = '/(?<![\w.@])@([A-Za-z0-9_]{2,30})\b/';

    public const URL_PATTERN = '~\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~i';

    /**
     * @return array<int, string>
     */
    public static function usernames(string $body): array
    {
        preg_match_all(self::PATTERN, preg_replace(self::URL_PATTERN, ' ', $body), $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    public static function firstUrl(string $body): ?string
    {
        return preg_match(self::URL_PATTERN, $body, $m) ? $m[0] : null;
    }

    /**
     * Escape the body, then link mentions and URLs.
     */
    public static function render(string $body): HtmlString
    {
        // One pass over both patterns, so an @ inside a URL is never turned into a mention.
        $combined = '~(?<url>'.trim(self::URL_PATTERN, '~i').')|(?<![\w.@])@(?<user>[A-Za-z0-9_]{2,30})\b~i';
        $out = '';
        $offset = 0;

        preg_match_all($combined, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $defined = [];
        $plain = fn (string $text) => self::glossary(e($text), $defined);

        foreach ($matches as $m) {
            [$text, $pos] = $m[0];
            $out .= $plain(substr($body, $offset, $pos - $offset));

            if (($m['url'][0] ?? '') !== '') {
                $label = str(preg_replace('~^https?://(www\.)?~', '', $text))->limit(40);
                $out .= '<a href="'.e($text).'" target="_blank" rel="nofollow ugc noopener" class="underline decoration-rule underline-offset-2 hover:decoration-ink">'.e($label).'</a>';
            } else {
                $out .= '<a href="'.e(url('@'.$m['user'][0])).'" wire:navigate class="font-medium text-flag hover:underline">@'.e($m['user'][0]).'</a>';
            }

            $offset = $pos + strlen($text);
        }

        return new HtmlString($out.$plain(substr($body, $offset)));
    }

    /**
     * Give local terms a hover definition, once per term per post.
     *
     * @param  array<string, bool>  $defined
     */
    protected static function glossary(string $escaped, array &$defined): string
    {
        foreach (config('stoop.glossary') as $term => $definition) {
            if (isset($defined[$term])) {
                continue;
            }

            $escaped = preg_replace_callback('/(?<![\w-])'.preg_quote(e($term), '/').'(?![\w-])/i', function ($m) use ($term, $definition, &$defined) {
                $defined[$term] = true;

                return '<abbr title="'.e($definition).'" class="cursor-help no-underline decoration-mute decoration-dotted underline-offset-4 [text-decoration-line:underline]">'.$m[0].'</abbr>';
            }, $escaped, 1);
        }

        return $escaped;
    }
}
