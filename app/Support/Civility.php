<?php

namespace App\Support;

/**
 * Text checks run before a dispatch goes out.
 */
class Civility
{
    /**
     * Reads as a personal attack, so ask the author to reconsider before posting.
     */
    public static function needsNudge(string $text): bool
    {
        return (bool) preg_match(config('stoop.nudge_pattern'), $text);
    }

    /**
     * Matches a held pattern, so publish it to nobody until a moderator approves it.
     */
    public static function shouldHold(string $text): bool
    {
        foreach (config('stoop.held_patterns') as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
