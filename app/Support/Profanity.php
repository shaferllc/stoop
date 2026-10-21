<?php

namespace App\Support;

/**
 * Stoop is swear-free: posts, replies, messages and names can't contain profanity.
 * Catches the usual disguises (f*ck, sh1t, fuuuck, f.u.c.k) while matching whole words
 * only, so "assistant", "class" and "cocktail" are fine. Slurs are handled separately
 * by the hate-speech check.
 */
class Profanity
{
    // Stems; common endings (-s, -ed, -ing, -er, -y) are matched automatically.
    protected const WORDS = [
        'fuck', 'motherfuck', 'shit', 'bullshit', 'bitch', 'asshole', 'ass', 'dumbass', 'jackass', 'smartass', 'badass',
        'bastard', 'damn', 'goddamn', 'crap', 'piss', 'cunt', 'twat', 'wanker', 'prick', 'pussy', 'cock', 'tits',
        'slut', 'whore', 'douche', 'douchebag', 'hell', 'wtf', 'stfu', 'gtfo', 'lmfao', 'fml',
    ];

    protected const LEET = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i'];

    public static function contains(?string $text): bool
    {
        return $text !== null && $text !== '' && preg_match(self::pattern(), strtr(mb_strtolower($text), self::LEET));
    }

    protected static function pattern(): string
    {
        return once(function () {
            $words = array_map(function (string $word) {
                // Each letter can repeat, be swapped for * or #, or be split by dots, dashes or spaces.
                $letters = array_map(fn ($c) => '(?:'.preg_quote($c, '/').'|[*#])+', str_split($word));

                return implode('[\s.\-_*#]{0,2}', $letters);
            }, self::WORDS);

            return '/(?<![a-z])(?:'.implode('|', $words).')(?:s|es|ed|er|ers|ing|in|y|ty|head|face|hole)?(?![a-z])/u';
        });
    }
}
