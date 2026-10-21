<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Staff's list of coded and local phrases (dog whistles, DC-specific stand-ins for race or
 * class). A new post with an active phrase is held for a moderator, who sees what it means.
 */
class WatchPhrases
{
    public const SEVERITIES = ['low', 'medium', 'high'];

    /**
     * Active phrases found in the text, most severe first.
     *
     * @return list<array{phrase: string, meaning: string, severity: string}>
     */
    public static function matches(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return DB::table('watch_phrases')->where('active', true)->get(['phrase', 'meaning', 'severity'])
            ->filter(fn ($p) => preg_match('/(?<![\p{L}\p{N}])'.str_replace(' ', '\s+', preg_quote($p->phrase, '/')).'(?![\p{L}\p{N}])/iu', $text))
            ->sortByDesc(fn ($p) => array_search($p->severity, self::SEVERITIES, true))
            ->map(fn ($p) => (array) $p)->values()->all();
    }
}
