<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Profanity;
use App\Support\Translator;
use Illuminate\Support\Str;

/**
 * Reply "@translate" (or "@translate to Spanish") in a thread and it translates the
 * dispatch you replied to, using the same translator as the Translate button.
 */
class Translate extends Bot
{
    // Other ways people name the languages, beyond Translator::LANGUAGES.
    protected const ALIASES = ['español' => 'es', 'espanol' => 'es', 'mandarin' => 'zh', 'cantonese' => 'zh', 'tiếng việt' => 'vi', 'አማርኛ' => 'am', '中文' => 'zh', '한국어' => 'ko', 'français' => 'fr', 'português' => 'pt', 'العربية' => 'ar'];

    public function username(): string
    {
        return 'translate';
    }

    public function name(): string
    {
        return 'Translate';
    }

    public function description(): string
    {
        return 'Reply “@translate to Spanish” in a thread to translate the dispatch above.';
    }

    public function wantsToReply(Dispatch $dispatch): bool
    {
        return $dispatch->parent_id && parent::wantsToReply($dispatch);
    }

    public function reply(Dispatch $dispatch): ?string
    {
        if (! Translator::enabled()) {
            return 'Translation isn’t available on Stoop yet.';
        }

        $parent = $dispatch->parent;
        if (! $parent || $parent->trashed() || $parent->status !== 'approved' || trim($parent->body) === '') {
            return null;
        }

        $language = $this->language($dispatch->body) ?? $dispatch->user->translation_language;
        $language = isset(Translator::LANGUAGES[$language]) ? $language : 'en';
        $translated = Translator::translate($parent->body, $language);

        if (! $translated || Profanity::contains($translated)) {
            return 'Sorry, I couldn’t translate that one right now.';
        }

        return Str::limit(Translator::LANGUAGES[$language].': '.$translated, config('stoop.max_length'));
    }

    /**
     * The language asked for after "@translate", like "to Spanish", "into ko" or "español".
     */
    public function language(string $body): ?string
    {
        if (! preg_match('/@translate\b\s*(?:(?:to|into|in)\s+)?([^\s.,!?]+(?:\s+việt)?)/iu', $body, $m)) {
            return null;
        }

        $asked = mb_strtolower($m[1]);
        $names = array_change_key_case(array_flip(Translator::LANGUAGES));

        return $names[$asked] ?? self::ALIASES[$asked] ?? (isset(Translator::LANGUAGES[$asked]) ? $asked : null);
    }
}
