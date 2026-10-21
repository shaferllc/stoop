<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Bots\Bot;
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

    public static function account(string $username, ?string $name = null): User
    {
        $profile = self::ACCOUNTS[$username] ?? ['name' => $name ?? Bot::find($username)?->name() ?? $username, 'email' => $username.'-bot@stoop.invalid'];

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

        if (preg_match('/^\W*(hi|hey|hello|howdy|yo|sup|good (morning|afternoon|evening)|help|what can you do|who are you|what is this)?\W*(there|stoop|everyone|all)?\W*$/i', $question)) {
            return 'Hi, I’m @stoop, the help desk. Things to try: “@metro next Dupont” for live trains, “@eats tacos near Shaw” for food picks, '
                .route('play').' for The Daily, Bingo and hunts, and '.route('bots').' for every bot. Ask me how anything on Stoop works, like “how do half-smokes work?”';
        }

        if ($key = Ai::key('content')) {
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

    // Words too common to say what a question is about.
    protected const STOPWORDS = ['the', 'and', 'can', 'how', 'what', 'who', 'why', 'when', 'where', 'does', 'did', 'you', 'your', 'are', 'for', 'with', 'this', 'that', 'there', 'have', 'has', 'get', 'any', 'about', 'stoop', 'from', 'into', 'just', 'like', 'want', 'way', 'they', 'them', 'out', 'all', 'one', 'not', 'yes'];

    // The page for a question the knowledge base doesn't cover, by the words that point to it.
    protected const PAGES = [
        'play' => ['/quiz|daily|trivia|game|bingo|hunt|play|streak|contest|puzzle/', 'Games live at :url: The Daily, Metro Bingo, scavenger hunts and the Line Cup.'],
        'happenings' => ['/event|happening|tonight|weekend|calendar|concert|show|party|rsvp|ticket|meetup/', 'What’s on around town is on the Happenings calendar: :url'],
        'bots' => ['/bot|metro|train|weather|food|eat|restaurant|bike|311|translat|cherry|blossom|home|price/', 'One of the bots probably knows. Here’s what each does: :url'],
        'settings' => ['/setting|account|password|email|notif|profile|token|api|privacy|delete|username|avatar|photo|stops?\b|station/', 'That’s in your settings: :url'],
    ];

    /**
     * The knowledge-base bullet that best matches the question: shared words (by stem), with
     * a word in the section's heading counting double. Falls back to the most likely page.
     */
    protected static function closestSection(string $knowledge, string $question): string
    {
        $stems = fn (string $text) => array_values(array_unique(array_map(
            fn ($w) => substr($w, 0, 5),
            array_filter(preg_split('/[^a-z0-9]+/', strtolower($text)), fn ($w) => strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true)),
        )));
        $asked = $stems($question);

        $section = '';
        $best = collect(preg_split('/\n+/', $knowledge))
            ->map(function ($line) use (&$section, $stems, $asked) {
                if (str_starts_with($line, '## ')) {
                    $section = substr($line, 3);
                }

                return str_starts_with($line, '- ')
                    ? ['text' => substr($line, 2), 'score' => count(array_intersect($asked, $stems($line))) + 2 * count(array_intersect($asked, $stems($section)))]
                    : null;
            })
            ->filter()->sortByDesc('score')->first();

        if ($best && $best['score'] > 0) {
            return Str::limit($best['text'], 400).(str_contains($best['text'], '/') ? '' : ' More: '.route('rules'));
        }

        foreach (self::PAGES as $page => [$pattern, $line]) {
            if (preg_match($pattern.'i', $question)) {
                return str_replace(':url', $page === 'settings' ? url('/settings') : route($page), $line);
            }
        }

        return 'I don’t have an answer for that one. The house rules cover how Stoop works: '.route('rules').'. Or ask neighbors with an Ask DC post.';
    }
}
