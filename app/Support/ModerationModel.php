<?php

namespace App\Support;

use Anthropic\Client;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Screens new dispatches with Claude before they go public. Only runs when an Anthropic
 * key is set. Returns 'allow' or 'hold'; a hold goes to human moderators, and anything
 * the check can't decide is held too, never approved.
 */
class ModerationModel
{
    protected static ?Closure $fake = null;

    protected const PROMPT = <<<'TXT'
        You screen posts for Stoop, a neighborhood social network for Washington, DC. Decide whether a post can go up right away or needs a human moderator first.

        Hold the post if it does any of these:
        - Attacks or demeans people for race, ethnicity, religion, gender, sexual orientation, disability, immigration status or national origin, including coded language
        - Describes a suspect or "suspicious person" mainly by race or ethnicity
        - Threatens, harasses or encourages harm to anyone
        - Shares someone's private information (home address, phone number) without consent
        - Promotes a political candidate or campaign
        - Is spam, a scam, or a sales or networking pitch

        Allow everything else, including strong opinions about places, policies, restaurants and neighborhood change, profanity that isn't aimed at a person, and criticism of public officials.

        Reply with exactly one word: allow or hold.
        TXT;

    public static function enabled(): bool
    {
        return self::$fake !== null || (Ai::key('moderation') && config('stoop.model_moderation'));
    }

    /**
     * @param  Closure(string): string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    public static function assess(string $text): string
    {
        if (self::$fake) {
            return (self::$fake)($text);
        }

        $message = (new Client(apiKey: Ai::key('moderation')))->messages->create(
            model: config('services.anthropic.moderation_model'),
            maxTokens: 512,
            outputConfig: ['effort' => 'low'],
            system: self::PROMPT,
            messages: [['role' => 'user', 'content' => "<post>\n".$text."\n</post>"]],
        );

        if ($message->stopReason === 'refusal') {
            return 'hold';
        }

        $answer = strtolower(trim((string) collect($message->content)->firstWhere('type', 'text')?->text));

        return str_starts_with($answer, 'allow') ? 'allow' : 'hold';
    }

    /**
     * Assess, treating any failure as "can't decide yet" (null) so the caller can retry.
     */
    public static function tryAssess(string $text): ?string
    {
        try {
            return self::assess($text);
        } catch (Throwable $e) {
            Log::warning('Moderation check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
