<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\Report;
use App\Models\SpamCluster;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Support\ClaudeText;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * @sentinel is for staff only and never posts. Every hour it counts the last hour's spam
 * clusters, held and watch-phrase posts, OpenAI flags and new reports, and sends moderators
 * a short summary in the app. Quiet hours send nothing. The latest summaries also show on
 * the admin Overview and at GET /api/admin/sentinel.
 */
class Sentinel extends Bot
{
    /** Summaries kept for the admin Overview. */
    public const KEEP = 24;

    public function username(): string
    {
        return 'sentinel';
    }

    public function name(): string
    {
        return 'Sentinel';
    }

    public function description(): string
    {
        return 'An hourly moderation summary for Stoop’s moderators. It doesn’t post or answer questions.';
    }

    // Mentions only ever get the how-to.
    public function wantsToReply(Dispatch $dispatch): bool
    {
        return false;
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->run())->name('bot:sentinel')->hourly()->withoutOverlapping();
    }

    /**
     * Summarize the last hour for moderators, once per hour. Returns the summary, or null when
     * there was nothing to say or this hour was already done.
     *
     * @return ?array{hour: string, at: string, counts: array<string, mixed>, text: string, ai: bool}
     */
    public function run(): ?array
    {
        $hour = now()->startOfHour();
        $latest = self::latest()[0] ?? null;

        if ($latest && $latest['hour'] === $hour->toIso8601String()) {
            return null;
        }

        $counts = self::counts($hour->copy()->subHour(), $hour);
        if (! array_filter($counts)) {
            return null;
        }

        $ai = ClaudeText::ask(
            'You write a two-sentence plain-text summary of the last hour of moderation activity on Stoop, a DC social network, for its moderators. Use only the counts given. Point out what most needs a look. No preamble, at most 300 characters.',
            json_encode($counts),
            300,
        );
        $summary = [
            'hour' => $hour->toIso8601String(),
            'at' => now()->toIso8601String(),
            'counts' => $counts,
            'text' => $ai ? Str::limit($ai, 400) : self::template($counts),
            'ai' => (bool) $ai,
        ];

        Settings::set('sentinel', array_slice([$summary, ...self::latest()], 0, self::KEEP));
        Notification::send(self::recipients(), new ModerationNotice('@sentinel: '.$summary['text']));

        return $summary;
    }

    /**
     * Only numbers and category names: no post bodies, no usernames.
     *
     * @return array{spam_clusters: int, held: int, watch_phrases: int, openai_flagged: int, openai_categories: array<string, int>, reports: int}
     */
    public static function counts(CarbonInterface $from, CarbonInterface $to): array
    {
        $window = fn ($q) => $q->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $screened = Dispatch::withTrashed()->where($window)->whereNotNull('screening')->get(['status', 'screening']);
        $categories = $screened->flatMap(fn ($d) => array_keys($d->screening['openai'] ?? []))->countBy()->sortDesc()->all();

        return [
            'spam_clusters' => SpamCluster::where('status', 'open')->where($window)->count(),
            'held' => Dispatch::where('status', 'held')->where($window)->count(),
            'watch_phrases' => $screened->filter(fn ($d) => ! empty($d->screening['watch']))->count(),
            'openai_flagged' => $screened->filter(fn ($d) => ! empty($d->screening['openai']))->count(),
            'openai_categories' => $categories,
            'reports' => Report::whereNull('resolved_at')->where($window)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public static function template(array $counts): string
    {
        $parts = array_filter([
            $counts['spam_clusters'] ? $counts['spam_clusters'].' new spam '.Str::plural('cluster', $counts['spam_clusters']) : null,
            $counts['held'] ? $counts['held'].' held '.Str::plural('post', $counts['held']) : null,
            $counts['watch_phrases'] ? $counts['watch_phrases'].' with a watch phrase' : null,
            $counts['openai_flagged'] ? $counts['openai_flagged'].' flagged by OpenAI ('.collect($counts['openai_categories'])->map(fn ($n, $c) => "$c $n")->join(', ').')' : null,
            $counts['reports'] ? $counts['reports'].' open '.Str::plural('report', $counts['reports']) : null,
        ]);

        return 'Last hour: '.implode('; ', $parts).'.';
    }

    /**
     * Newest first.
     *
     * @return array<int, array{hour: string, at: string, counts: array<string, mixed>, text: string, ai: bool}>
     */
    public static function latest(int $limit = self::KEEP): array
    {
        return array_slice(Settings::get('sentinel', []), 0, $limit);
    }

    /**
     * Staff whose roles include moderation (admins too).
     *
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::where('is_staff', true)->get()->filter(fn (User $u) => $u->hasRole('moderator') && ! $u->isHouseAccount());
    }
}
