<?php

namespace App\Support;

use Anthropic\Client;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claude helping moderators: a suggested call on each queue item, a second look at posts
 * OpenAI found borderline, a brief on a person's record, and notes on appeals. It only
 * suggests or holds; a moderator makes every decision. Off without ANTHROPIC_API_KEY.
 */
class ModerationAssist
{
    protected static ?Closure $fake = null;

    public static function enabled(): bool
    {
        return self::$fake !== null || (bool) Ai::key('moderation');
    }

    /**
     * @param  Closure(string $system, string $prompt): string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    /**
     * Claude's reply as text, or null if it declined or the call failed.
     */
    protected static function ask(string $system, string $prompt): ?string
    {
        if (self::$fake) {
            return (self::$fake)($system, $prompt);
        }

        try {
            $message = (new Client(apiKey: Ai::key('moderation')))->messages->create(
                model: config('services.anthropic.moderation_model'),
                maxTokens: 1024,
                outputConfig: ['effort' => 'low'],
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
            );
        } catch (Throwable $e) {
            Log::warning('Moderation assist failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }

        return trim((string) collect($message->content)->firstWhere('type', 'text')?->text) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function askJson(string $system, string $prompt): ?array
    {
        $text = self::ask($system."\n\nReply with only the JSON object, no other text.", $prompt);
        $json = $text !== null && preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;

        return is_array($json) ? $json : null;
    }

    protected static function context(Dispatch $d): string
    {
        $d->loadMissing(['parent', 'reports']);

        return "<post>\n".$d->body."\n</post>\n"
            .($d->parent ? "<replying_to>\n".$d->parent->body."\n</replying_to>\n" : '')
            .'<screening>'.json_encode($d->screening ?? [])."</screening>\n"
            .'<reports>'.json_encode($d->reports->whereNull('resolved_at')->map(fn ($r) => ['reason' => config('stoop.report_reasons.'.$r->reason, $r->reason), 'note' => $r->note])->values())."</reports>\n"
            .'<author_penalties_last_90_days>'.Strikes::recent($d->user).'</author_penalties_last_90_days>';
    }

    /**
     * Claude's suggested call on a queue item, kept in moderation_notes so it's asked once.
     *
     * @return array{explanation: string, rule: ?string, action: string, penalty: string, confidence: string}|null
     */
    public static function suggestion(Dispatch $dispatch, bool $compute = true): ?array
    {
        $cached = DB::table('moderation_notes')->where('kind', 'suggestion')->where('dispatch_id', $dispatch->id)->value('payload');
        if ($cached || ! $compute || ! self::enabled()) {
            return $cached ? json_decode($cached, true) : null;
        }

        $rules = config('stoop.rule_reasons');
        $penalties = ['none', 'warning', ...array_map('strval', array_keys(Penalty::TIMEOUTS))];
        $json = self::askJson(
            'You help moderators of Stoop, a neighborhood social network for Washington, DC, decide on posts in their review queue. Stoop is strict about hate, including coded and local dog whistles, and about profiling, threats, campaign posts and spam. Strong opinions about places, policies and neighborhood change are fine. '
            .'Give a JSON object: "explanation" (one plain sentence a moderator can scan), "action" ("approve" or "remove"), "rule" (when removing, exactly one of: '.json_encode($rules).'; otherwise null), '
            .'"penalty" (when removing, one of '.json_encode($penalties).' where numbers are timeout hours; "none" when approving; never a ban, that is a human call), and "confidence" ("low", "medium" or "high").',
            self::context($dispatch),
        );
        if ($json === null) {
            return null;
        }

        $remove = ($json['action'] ?? null) === 'remove';
        $suggestion = [
            'explanation' => str((string) ($json['explanation'] ?? ''))->squish()->limit(300)->toString(),
            'action' => $remove ? 'remove' : 'approve',
            'rule' => $remove && in_array($json['rule'] ?? null, $rules, true) ? $json['rule'] : null,
            'penalty' => $remove && in_array((string) ($json['penalty'] ?? ''), $penalties, true) ? (string) $json['penalty'] : 'none',
            'confidence' => in_array($json['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $json['confidence'] : 'low',
        ];

        DB::table('moderation_notes')->insert(['kind' => 'suggestion', 'dispatch_id' => $dispatch->id, 'payload' => json_encode($suggestion), 'created_at' => now(), 'updated_at' => now()]);

        return $suggestion;
    }

    /**
     * Scores between half the hold threshold and the hold threshold in the hate and
     * harassment categories: not enough to hold, enough to look again for coded hate.
     *
     * @param  array<string, float>  $scores
     * @return array<string, float>
     */
    public static function borderline(array $scores): array
    {
        return collect(config('stoop.openai_thresholds'))
            ->filter(fn ($t, $c) => in_array(strtok($c, '/'), ['hate', 'harassment'], true) && ($scores[$c] ?? 0) >= $t / 2 && ($scores[$c] ?? 0) < $t)
            ->map(fn ($t, $c) => round($scores[$c], 3))->all();
    }

    /**
     * Claude's second look at a borderline post for coded or local hate. Returns why it
     * should be held, or null to let the rest of the pipeline decide. It can only hold.
     *
     * @param  array<string, float>  $borderline
     */
    public static function codedHate(string $body, array $borderline): ?string
    {
        $watch = DB::table('watch_phrases')->where('active', true)->pluck('meaning', 'phrase');
        $json = self::askJson(
            'You give a second look to posts on Stoop, a neighborhood social network for Washington, DC. An automatic check scored this post as close to, but under, its line for hate or harassment. '
            .'Look for coded or local hate: dog whistles, stand-ins for race, class or immigration status (for example DC neighborhoods, wards or housing programs used to mean a group of people), and "just asking" framing. '
            .'Staff watch for phrases like these: '.json_encode($watch).'. Ordinary criticism of places, policies, prices and neighborhood change is fine. '
            .'Give a JSON object: "hold" (true only if a moderator should look before it goes up) and "reason" (one sentence for the moderator).',
            "<post>\n".$body."\n</post>\n<scores>".json_encode($borderline).'</scores>',
        );

        return ($json['hold'] ?? false) === true ? str((string) ($json['reason'] ?? 'Possible coded hate.'))->squish()->limit(300)->toString() : null;
    }

    /**
     * A short read on a person's record and how their tone has changed. Claude's version is
     * cached for an hour; without a key (or if Claude fails) it's the plain facts.
     *
     * @return array{ai: bool, text: string, facts: array<string, mixed>}
     */
    public static function brief(User $user): array
    {
        $facts = ModerationInsights::personFacts($user);
        if (! self::enabled()) {
            return ['ai' => false, 'text' => $facts['text'], 'facts' => $facts];
        }

        $text = Cache::get($key = 'moderation-brief:'.$user->id);
        if ($text === null) {
            $posts = Dispatch::withTrashed()->where('user_id', $user->id)->latest()->take(40)->get(['body', 'status', 'created_at'])->reverse()
                ->map(fn ($d) => $d->created_at->toDateString().' ['.$d->status.'] '.str($d->body)->limit(280))->join("\n");
            $penalties = $user->penalties()->oldest()->get()->map(fn ($p) => $p->created_at->toDateString().' '.$p->type.': '.$p->reason)->join("\n");

            $text = self::ask(
                'You brief moderators of Stoop, a DC neighborhood social network, on one member. In 3 to 5 plain sentences: summarize their strike history, then how the tone of their posts has changed over time (better, worse or steady), with dates. Only use what is given. No advice on penalties.',
                '<facts>'.$facts['text']."</facts>\n<penalties>\n".$penalties."\n</penalties>\n<posts oldest_first=\"true\">\n".$posts."\n</posts>",
            );
            if ($text === null) {
                return ['ai' => false, 'text' => $facts['text'], 'facts' => $facts];
            }
            Cache::put($key, $text, now()->addHour());
        }

        return ['ai' => true, 'text' => $text, 'facts' => $facts];
    }

    /**
     * Sets an appeal against the original post, the removal reason and the screening scores,
     * listing what supports the appeal and what contradicts it. Asked once per appeal.
     *
     * @return array{summary: string, supports: list<string>, contradicts: list<string>}|null
     */
    public static function appeal(Appeal $appeal): ?array
    {
        $cached = DB::table('moderation_notes')->where('kind', 'appeal')->where('appeal_id', $appeal->id)->value('payload');
        if ($cached || ! self::enabled()) {
            return $cached ? json_decode($cached, true) : null;
        }

        $appeal->loadMissing(['penalty', 'dispatch']);
        $json = self::askJson(
            'You help a second moderator of Stoop, a DC neighborhood social network, review an appeal. Compare what the member says in the appeal with the original post, the reason it was removed and the automatic screening scores. '
            .'Give a JSON object: "summary" (one sentence), "supports" (short points backing the appeal, for example the post does not say what the reason claims) and "contradicts" (short points where the appeal conflicts with the post or the scores). Do not decide the appeal.',
            "<appeal>\n".$appeal->body."\n</appeal>\n<original_post>\n".($appeal->dispatch?->body ?? '(no post, a penalty on the account)')."\n</original_post>\n"
            .'<removal_reason>'.($appeal->penalty?->reason ?? $appeal->dispatch?->moderation_reason)."</removal_reason>\n<screening>".json_encode($appeal->dispatch?->screening ?? []).'</screening>',
        );
        if ($json === null) {
            return null;
        }

        $points = fn ($list) => collect(is_array($list) ? $list : [])->filter(fn ($p) => is_string($p) && trim($p) !== '')->map(fn ($p) => str($p)->squish()->limit(240)->toString())->take(5)->values()->all();
        $notes = [
            'summary' => str((string) ($json['summary'] ?? ''))->squish()->limit(300)->toString(),
            'supports' => $points($json['supports'] ?? []),
            'contradicts' => $points($json['contradicts'] ?? []),
        ];
        DB::table('moderation_notes')->insert(['kind' => 'appeal', 'appeal_id' => $appeal->id, 'payload' => json_encode($notes), 'created_at' => now(), 'updated_at' => now()]);

        return $notes;
    }
}
