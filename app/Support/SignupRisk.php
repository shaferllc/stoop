<?php

namespace App\Support;

use App\Models\User;

/**
 * Scores a new account from what we know at sign-up. Staff see the score and reasons;
 * a high score holds the account's first posts for a moderator. It never blocks sign-up.
 */
class SignupRisk
{
    /** Points per signal, out of 100. */
    public const SIGNALS = [
        'disposable_email' => [40, 'Throwaway email domain'],
        'ip_burst' => [30, 'Several sign-ups from the same network in an hour'],
        'turnstile_failed' => [30, 'Didn’t pass the Turnstile bot check'],
        'random_username' => [20, 'Random-looking username'],
    ];

    /** Approved posts after which a high-risk account posts normally. */
    public const HELD_POSTS = 3;

    /**
     * @param  bool|null  $turnstile  The bot check's result, or null when it isn't set up
     */
    public static function assess(User $user, ?string $ip, ?bool $turnstile): void
    {
        $ipHash = $ip ? hash_hmac('sha256', 'ip|'.$ip, (string) config('app.key')) : null;
        $domain = strtolower((string) str($user->email)->afterLast('@'));

        $hits = array_keys(array_filter([
            'disposable_email' => in_array($domain, config('stoop.disposable_email_domains', []), true),
            'ip_burst' => $ipHash && User::where('signup_ip_hash', $ipHash)->where('created_at', '>=', now()->subHour())->whereKeyNot($user->id)->count() >= 2,
            'turnstile_failed' => $turnstile === false,
            'random_username' => self::looksRandom((string) $user->username),
        ]));

        $user->forceFill([
            'signup_ip_hash' => $ipHash,
            'risk_score' => min(100, array_sum(array_map(fn ($k) => self::SIGNALS[$k][0], $hits))),
            'risk_reasons' => array_map(fn ($k) => self::SIGNALS[$k][1], $hits),
        ])->save();
    }

    /**
     * Long digit runs, five consonants in a row, or letters and digits interleaved.
     */
    public static function looksRandom(string $username): bool
    {
        return (bool) preg_match('/\d{4,}|[bcdfghjklmnpqrstvwxz]{5,}|([a-z]\d){3,}|(\d[a-z]){3,}/i', $username);
    }

    /**
     * Whether a new post from this person should wait for a moderator.
     */
    public static function holds(User $user): bool
    {
        return $user->risk_score >= config('stoop.signup_risk_hold')
            && $user->created_at?->gt(now()->subWeek())
            && $user->dispatches()->where('status', 'approved')->count() < self::HELD_POSTS;
    }
}
