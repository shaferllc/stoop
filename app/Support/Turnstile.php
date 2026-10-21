<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile, the bot check on the sign-up form.
 */
class Turnstile
{
    public static function passes(?string $token): bool
    {
        if (! $token) {
            return false;
        }

        try {
            return (bool) Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => request()->ip(),
            ])->json('success');
        } catch (Throwable) {
            return false;
        }
    }
}
