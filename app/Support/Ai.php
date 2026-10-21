<?php

namespace App\Support;

/**
 * Which jobs may call a paid AI model. Moderation only by default (STOOP_AI_FEATURES=moderation);
 * everything else runs on plain rules and free public data. Add "content" to allow the rest.
 */
class Ai
{
    public static function allows(string $feature): bool
    {
        return in_array($feature, config('stoop.ai_features', ['moderation']), true);
    }

    /** The Anthropic key, but only for a feature that's allowed to use it. */
    public static function key(string $feature): ?string
    {
        return self::allows($feature) ? (config('services.anthropic.key') ?: null) : null;
    }
}
