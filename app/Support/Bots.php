<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stoop's two house accounts: @stoop answers questions about Stoop, and @dc says hello.
 */
class Bots
{
    public const ACCOUNTS = [
        'stoop' => ['name' => 'Stoop', 'email' => 'stoop-bot@stoop.invalid'],
        'dc' => ['name' => 'DC', 'email' => 'dc-bot@stoop.invalid'],
    ];

    public static function account(string $username): User
    {
        $profile = self::ACCOUNTS[$username];

        if ($bot = User::where('username', $username)->first()) {
            return $bot;
        }

        $bot = new User;
        $bot->forceFill([
            'username' => $username,
            'name' => $profile['name'],
            'email' => $profile['email'],
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'accepted_rules_at' => now(),
            'is_bot' => true,
        ])->save();

        return $bot;
    }

    public static function isGreeting(string $body): bool
    {
        return (bool) preg_match('/^\W*hello,?\s+(dc|d\.c\.?|washington)\W*$/i', trim($body));
    }

    /**
     * An answer to a question about Stoop. Uses Claude when an API key is configured,
     * and otherwise the closest section of the knowledge base.
     */
    public static function answer(string $question): string
    {
        $knowledge = self::knowledge();
        $question = trim(preg_replace('/(?<![\w.@])@stoop\b/i', '', $question));

        if ($key = config('services.anthropic.key')) {
            try {
                $message = (new Client(apiKey: $key))->messages->create(
                    model: config('services.anthropic.model'),
                    maxTokens: 1024,
                    outputConfig: ['effort' => 'low'],
                    system: "You are @stoop, the help account on Stoop, a DC social network. Answer the person's question in plain text, in at most 400 characters, using only this knowledge base. If it doesn't cover the question, say you don't know and point them to /rules.\n\n".$knowledge,
                    messages: [['role' => 'user', 'content' => $question]],
                );

                if ($message->stopReason !== 'refusal') {
                    foreach ($message->content as $block) {
                        if ($block->type === 'text' && trim($block->text) !== '') {
                            return Str::limit(trim($block->text), 480);
                        }
                    }
                }
            } catch (APIException $e) {
                Log::warning('Stoop bot could not reach Claude', ['error' => $e->getMessage()]);
            }
        }

        return self::closestSection($knowledge, $question);
    }

    /**
     * What @stoop knows: the entries staff edit in Admin → Bots, as Markdown sections. Falls
     * back to resources/knowledge/stoop.md, which also seeded the entries.
     */
    public static function knowledge(): string
    {
        $entries = DB::table('bot_knowledge')->orderBy('id')->get(['section', 'body']);

        if ($entries->isEmpty()) {
            return file_get_contents(resource_path('knowledge/stoop.md'));
        }

        return "# About Stoop\n\n".$entries->groupBy('section')
            ->map(fn ($rows, $section) => "## $section\n".$rows->map(fn ($r) => '- '.$r->body)->implode("\n"))
            ->implode("\n\n");
    }

    /**
     * The knowledge-base bullet sharing the most words with the question.
     */
    protected static function closestSection(string $knowledge, string $question): string
    {
        $words = fn (string $text) => array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($text)),
            fn ($w) => strlen($w) > 3,
        ));
        $asked = $words($question);

        $best = collect(preg_split('/\n+/', $knowledge))
            ->filter(fn ($line) => str_starts_with($line, '- '))
            ->sortByDesc(fn ($line) => count(array_intersect($asked, $words($line))))
            ->first();

        return $best && array_intersect($asked, $words($best))
            ? ltrim($best, '- ')
            : 'I’m not sure about that one. The house rules at /rules cover most of how Stoop works.';
    }
}
