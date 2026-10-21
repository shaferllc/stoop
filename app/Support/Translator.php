<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Translates dispatch text on request. Needs an Anthropic API key; without one, the
 * Translate button doesn't show. Translations are cached for 7 days by text and language.
 */
class Translator
{
    public const LANGUAGES = ['en' => 'English', 'es' => 'Spanish', 'am' => 'Amharic', 'fr' => 'French', 'zh' => 'Chinese', 'ko' => 'Korean', 'vi' => 'Vietnamese', 'ar' => 'Arabic', 'pt' => 'Portuguese'];

    protected const ENGLISH = ['the', 'and', 'a', 'to', 'of', 'is', 'in', 'it', 'you', 'that', 'for', 'on', 'my', 'this', 'with', 'at', 'was', 'be', 'are', 'have', 'i', 'we', 'they', 'just', 'so', 'but', 'not', 'here', 'there', 'about', 'what', 'any', 'anyone', 'near', 'got', 'get', 'go', 'went'];

    public static function enabled(): bool
    {
        return (bool) Ai::key('content');
    }

    /**
     * Rough guess at whether text is in another language than the reader's.
     */
    public static function offer(string $text, string $reader): bool
    {
        if (! self::enabled() || mb_strlen($text) < 12) {
            return false;
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 3) {
            return false;
        }

        $nonLatin = preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $text);
        $englishShare = count(array_intersect($words, self::ENGLISH)) / count($words);
        $looksEnglish = ! $nonLatin && $englishShare >= 0.08;

        return $reader === 'en' ? ! $looksEnglish : $looksEnglish;
    }

    public static function translate(string $text, string $language): ?string
    {
        $hash = hash('sha256', $text);
        $cached = DB::table('translations')->where(['content_hash' => $hash, 'language' => $language])
            ->where('created_at', '>', now()->subDays(7))->value('text');

        if ($cached !== null) {
            return $cached;
        }

        try {
            $message = (new Client(apiKey: Ai::key('content')))->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: 2048,
                outputConfig: ['effort' => 'low'],
                system: 'Translate the user’s text into '.(self::LANGUAGES[$language] ?? 'English').'. Keep @mentions, links, names and place names as they are. Reply with only the translation.',
                messages: [['role' => 'user', 'content' => $text]],
            );
        } catch (APIException $e) {
            Log::warning('Translation failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }

        $translated = collect($message->content)->firstWhere('type', 'text')?->text;
        if (! $translated) {
            return null;
        }

        DB::table('translations')->upsert(
            [['content_hash' => $hash, 'language' => $language, 'text' => trim($translated), 'created_at' => now()]],
            ['content_hash', 'language'], ['text', 'created_at'],
        );

        return trim($translated);
    }
}
