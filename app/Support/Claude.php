<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * One short text answer from Claude, for the summaries and suggestions. Off (null) when
 * there's no ANTHROPIC_API_KEY, on a refusal, or when the API can't be reached, so every
 * caller needs a plain fallback.
 */
class Claude
{
    protected static ?Closure $fake = null;

    public static function enabled(): bool
    {
        return self::$fake !== null || (bool) config('services.anthropic.key');
    }

    /**
     * @param  Closure(string $system, string $user): ?string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    public static function text(string $system, string $user, int $maxTokens = 1024): ?string
    {
        if (self::$fake) {
            return (self::$fake)($system, $user);
        }

        if (! $key = config('services.anthropic.key')) {
            return null;
        }

        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: $maxTokens,
                outputConfig: ['effort' => 'low'],
                system: $system,
                messages: [['role' => 'user', 'content' => $user]],
            );
        } catch (APIException $e) {
            Log::warning('Claude request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }

        $text = trim((string) collect($message->content)->firstWhere('type', 'text')?->text);

        return $text === '' ? null : $text;
    }
}
