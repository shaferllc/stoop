<?php

namespace App\Support;

use Anthropic\Client;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A short plain-text answer from Claude for house bots and staff drafting tools. Off when
 * no Anthropic key is set; returns null on any failure so callers fall back to plain rules.
 */
class ClaudeText
{
    protected static ?Closure $fake = null;

    public static function enabled(): bool
    {
        return self::$fake !== null || (bool) config('services.anthropic.key');
    }

    /**
     * @param  Closure(string $system, string $prompt): ?string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    public static function ask(string $system, string $prompt, int $maxTokens = 1024): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        try {
            if (self::$fake) {
                return (self::$fake)($system, $prompt);
            }

            $message = (new Client(apiKey: config('services.anthropic.key')))->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: $maxTokens,
                outputConfig: ['effort' => 'low'],
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
            );

            if ($message->stopReason === 'refusal') {
                return null;
            }

            $text = trim((string) collect($message->content)->firstWhere('type', 'text')?->text);

            return $text === '' ? null : $text;
        } catch (Throwable $e) {
            Log::warning('Claude text request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
