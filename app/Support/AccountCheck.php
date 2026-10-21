<?php

namespace App\Support;

use Anthropic\Client;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Claude whether an account looks like a bot or part of a spam ring, from its recent
 * activity. Only a suggestion for staff: nothing happens to the account. Without an API key,
 * or when Claude can't answer, it falls back to the heuristic signals.
 */
class AccountCheck
{
    public const VERDICTS = ['bot' => 'Likely a bot', 'spam_ring' => 'Likely part of a spam ring', 'human' => 'Looks like a person', 'unclear' => 'Unclear'];

    protected static ?Closure $fake = null;

    protected const PROMPT = <<<'TXT'
        You help moderators of Stoop, a neighborhood social network for Washington, DC, spot bots and coordinated spam. You get one account's sign-up signals and recent posts. Judge only from what's there; new or quiet accounts are usually just people.

        Reply with only a JSON object: {"verdict": "bot" | "spam_ring" | "human" | "unclear", "confidence": 0-100, "reasons": ["short reason", ...]} with at most 4 reasons.
        TXT;

    public static function enabled(): bool
    {
        return self::$fake !== null || (bool) Ai::key('moderation');
    }

    /**
     * @param  Closure(string): string  $fake  Returns Claude's raw reply for the prompt
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    /**
     * @return array{verdict: string, label: string, confidence: int, reasons: array<int, string>, source: string}
     */
    public static function run(User $user): array
    {
        if (self::enabled()) {
            try {
                $reply = self::ask(self::activity($user));
                $json = preg_match('/\{.*\}/s', $reply, $m) ? json_decode($m[0], true) : null;

                if (is_array($json) && isset(self::VERDICTS[$json['verdict'] ?? ''])) {
                    return self::result($json['verdict'], (int) ($json['confidence'] ?? 50), array_slice(array_map('strval', (array) ($json['reasons'] ?? [])), 0, 4), 'claude');
                }
            } catch (Throwable $e) {
                Log::warning('Account check could not reach Claude', ['error' => $e->getMessage()]);
            }
        }

        return self::heuristic($user);
    }

    /**
     * The signals we already have: sign-up risk, spam clusters, repeated posts and links.
     *
     * @return array{verdict: string, label: string, confidence: int, reasons: array<int, string>, source: string}
     */
    public static function heuristic(User $user): array
    {
        $reasons = $user->risk_reasons ?? [];
        $score = (int) $user->risk_score;

        $clusters = SpamRings::for($user);
        foreach ($clusters as $cluster) {
            $reasons[] = 'In a spam cluster: '.$cluster->summary;
        }

        $posts = $user->dispatches()->latest()->take(30)->pluck('body');
        $repeats = $posts->count() - $posts->map(fn ($b) => SpamRings::normalize($b))->unique()->count();
        if ($posts->count() >= 3 && $repeats >= $posts->count() / 2) {
            $reasons[] = 'Posts the same text over and over';
            $score += 30;
        }
        if ($posts->count() >= 3 && $posts->filter(fn ($b) => SpamRings::domains($b))->count() >= $posts->count() * 0.6) {
            $reasons[] = 'Most posts carry a link';
            $score += 20;
        }

        $verdict = match (true) {
            $clusters->isNotEmpty() => 'spam_ring',
            $score >= 60 => 'bot',
            $score >= 30 => 'unclear',
            default => 'human',
        };

        return self::result($verdict, $clusters->isNotEmpty() ? 70 : min(90, max(30, $verdict === 'human' ? 100 - $score : $score)), $reasons ?: ['Nothing unusual in the sign-up or recent posts'], 'heuristic');
    }

    /**
     * What Claude sees. No email address or IP: only the email's domain type and the posts.
     */
    protected static function activity(User $user): string
    {
        $posts = $user->dispatches()->withTrashed()->latest()->take(30)->get()
            ->map(fn ($d) => '['.$d->created_at?->toDateTimeString().', '.$d->status.($d->parent_id ? ', reply' : '').'] '.$d->body)->implode("\n");

        return "<account>\nUsername: @{$user->username}\nDisplay name: {$user->name}\nJoined: {$user->created_at?->diffForHumans()}\n"
            .'Sign-up signals: '.(implode('; ', $user->risk_reasons ?? []) ?: 'none')."\n"
            .'Spam clusters: '.(SpamRings::for($user)->pluck('summary')->implode('; ') ?: 'none')."\n</account>\n<posts>\n".($posts ?: '(no posts)')."\n</posts>";
    }

    protected static function ask(string $activity): string
    {
        if (self::$fake) {
            return (self::$fake)($activity);
        }

        $message = (new Client(apiKey: Ai::key('moderation')))->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 1024,
            outputConfig: ['effort' => 'low'],
            system: self::PROMPT,
            messages: [['role' => 'user', 'content' => $activity]],
        );

        if ($message->stopReason === 'refusal') {
            return '';
        }

        return (string) collect($message->content)->firstWhere('type', 'text')?->text;
    }

    /**
     * @param  array<int, string>  $reasons
     * @return array{verdict: string, label: string, confidence: int, reasons: array<int, string>, source: string}
     */
    protected static function result(string $verdict, int $confidence, array $reasons, string $source): array
    {
        return ['verdict' => $verdict, 'label' => self::VERDICTS[$verdict], 'confidence' => max(0, min(100, $confidence)), 'reasons' => array_values($reasons), 'source' => $source];
    }
}
