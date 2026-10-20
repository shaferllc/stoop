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

    /**
     * Words that describe identity rather than anything you could pick someone out by.
     */
    protected const IDENTITY_WORDS = [
        'black', 'white', 'hispanic', 'latino', 'latina', 'latinx', 'asian', 'african', 'american',
        'arab', 'middle', 'eastern', 'indian', 'native', 'mexican', 'salvadoran', 'ethiopian',
        'chinese', 'korean', 'muslim', 'jewish', 'immigrant', 'foreign', 'dark', 'light', 'skinned',
        'man', 'men', 'woman', 'women', 'male', 'female', 'guy', 'guys', 'kid', 'kids', 'teen', 'teens',
        'person', 'people', 'young', 'old', 'a', 'an', 'the', 'and', 'or', 'with', 'of', 'some', 'two', 'three',
    ];

    /**
     * A description of a person that only gives race, ethnicity or gender. Safety posts must
     * describe clothing, height, a vehicle or what happened instead.
     */
    public static function isIdentityOnly(string $description): bool
    {
        $words = preg_split('/[^a-z]+/', strtolower($description), -1, PREG_SPLIT_NO_EMPTY);
        $useful = array_diff($words, self::IDENTITY_WORDS);

        return $words !== [] && count($useful) < 3;
    }
}
