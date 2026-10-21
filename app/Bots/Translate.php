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
    protected const ALIASES = ['español' => 'es', 'espanol' => 'es', 'mandarin' => 'zh', 'cantonese' => 'zh', 'አማርኛ' => 'am', '中文' => 'zh', '한국어' => 'ko', 'français' => 'fr', 'português' => 'pt', 'العربية' => 'ar'];

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

    public function reply(Dispatch $dispatch): ?string
    {
        if (! $dispatch->parent_id) {
            return 'Reply to a dispatch with “@translate to Spanish” (or Amharic, French, Chinese, Korean, Vietnamese, Arabic, Portuguese or English) and I’ll translate the post you replied to.'
                .(Translator::enabled() ? '' : ' Translation is switched off on Stoop right now, though.');
        }

        if (! Translator::enabled()) {
            return 'Translation is switched off on Stoop right now. Your phone or browser can translate the post above: long-press or right-click the text and choose Translate.';
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

        $label = Translator::LANGUAGES[$language].': ';

        return $label.Str::limit($translated, config('stoop.max_length') - mb_strlen($label) - 1, '…');
    }

    /**
     * The language asked for after "@translate", like "this to Spanish", "into ko" or "español".
     * A bare code only counts right after the mention or after to/into/in ("I am" isn't Amharic).
     */
    public function language(string $body): ?string
    {
        if (! preg_match('/@translate\b(.*)/isu', $body, $m)) {
            return null;
        }

        $words = preg_split('/[^\p{L}\p{M}]+/u', str_replace('tiếng việt', 'tiếngviệt', mb_strtolower($m[1])), -1, PREG_SPLIT_NO_EMPTY);
        $names = [...array_change_key_case(array_flip(Translator::LANGUAGES)), ...self::ALIASES, 'tiếngviệt' => 'vi'];

        foreach ($words as $i => $word) {
            if (isset($names[$word])) {
                return $names[$word];
            }
            if (isset(Translator::LANGUAGES[$word]) && ($i === 0 || in_array($words[$i - 1], ['to', 'into', 'in'], true))) {
                return $word;
            }
        }

        return null;
    }
}
