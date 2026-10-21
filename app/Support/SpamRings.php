<?php

namespace App\Support;

use App\Actions\Moderate;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\SpamCluster;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds accounts that look like they're working together and lists them for moderators.
 * Detection only records clusters; a moderator decides what to do with each one.
 */
class SpamRings
{
    /** Accounts younger than this count as new. */
    public const NEW_DAYS = 7;

    /** Distinct accounts it takes to call something a ring. */
    public const MIN_ACCOUNTS = 3;

    /** Share of three-word phrases two posts need in common to count as the same post. */
    public const SIMILARITY = 0.7;

    /** Posts shorter than this many words are too generic to compare ("same here"). */
    public const MIN_WORDS = 6;

    public const BURST_ACCOUNTS = 4;

    public const BURST_HOURS = 6;

    public const DM_PEOPLE = 10;

    public const RULE = 'Spam or networking pitches';

    /**
     * Runs every detector and returns how many clusters are open.
     */
    public static function detect(): int
    {
        self::duplicates();
        self::threadBursts();
        self::linkSpam();
        self::messageBlasts();

        return SpamCluster::where('status', 'open')->count();
    }

    /**
     * Near-identical posts from different accounts in the last day.
     */
    public static function duplicates(): void
    {
        // ponytail: compares every pair of the last day's posts (capped at 1,000); switch to MinHash buckets if volume grows
        $posts = self::humanPosts()->where('dispatches.created_at', '>=', now()->subDay())
            ->latest('dispatches.id')->take(1000)->get(['dispatches.id', 'dispatches.user_id', 'dispatches.body', 'dispatches.created_at'])
            ->map(fn ($p) => $p->setAttribute('shingles', self::shingles($p->body)))
            ->filter(fn ($p) => $p->shingles)->values();
        if ($posts->isEmpty()) {
            return;
        }

        $parent = range(0, $posts->count() - 1);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : $parent[$i] = $find($parent[$i]);
        };

        foreach ($posts as $i => $a) {
            foreach ($posts as $j => $b) {
                if ($j > $i && $a->user_id !== $b->user_id && self::similarity($a->shingles, $b->shingles) >= self::SIMILARITY) {
                    $parent[$find($i)] = $find($j);
                }
            }
        }

        $posts->groupBy(fn ($p, $i) => $find($i))
            ->filter(fn ($group) => $group->unique('user_id')->count() >= self::MIN_ACCOUNTS)
            ->each(function (Collection $group) {
                $first = $group->sortBy('id')->first();
                self::record('duplicate', md5(self::normalize($first->body)), $group->unique('user_id')->count().' accounts posted near-identical text', $group);
            });
    }

    /**
     * A burst of new accounts replying to one thread.
     */
    public static function threadBursts(): void
    {
        self::humanPosts(newOnly: true)->whereNotNull('dispatches.parent_id')
            ->where('dispatches.created_at', '>=', now()->subHours(self::BURST_HOURS))
            ->get(['dispatches.id', 'dispatches.user_id', 'dispatches.parent_id', 'dispatches.body', 'dispatches.created_at'])
            ->groupBy('parent_id')
            ->filter(fn ($replies) => $replies->unique('user_id')->count() >= self::BURST_ACCOUNTS)
            ->each(fn ($replies, $parentId) => self::record('thread_burst', 'thread:'.$parentId,
                $replies->unique('user_id')->count().' new accounts replied to dispatch #'.$parentId.' within '.self::BURST_HOURS.' hours', $replies));
    }

    /**
     * The same link domain posted by several new accounts in the last day.
     */
    public static function linkSpam(): void
    {
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        self::humanPosts(newOnly: true)->where('dispatches.created_at', '>=', now()->subDay())->where('dispatches.body', 'like', '%.%')
            ->get(['dispatches.id', 'dispatches.user_id', 'dispatches.body', 'dispatches.created_at'])
            ->flatMap(fn ($p) => collect(self::domains($p->body))->map(fn ($domain) => [$domain, $p]))
            ->reject(fn ($pair) => $pair[0] === $own)
            ->groupBy(fn ($pair) => $pair[0])
            ->map(fn ($pairs) => collect($pairs)->pluck(1))
            ->filter(fn ($posts) => $posts->unique('user_id')->count() >= self::MIN_ACCOUNTS)
            ->each(fn ($posts, $domain) => self::record('link', 'link:'.$domain, $posts->unique('user_id')->count().' new accounts linked to '.$domain, $posts));
    }

    /**
     * New accounts that messaged many different people in the last day. One cluster a day.
     */
    public static function messageBlasts(): void
    {
        $senders = DB::table('messages')
            ->join('conversation_participants as p', fn ($j) => $j->on('p.conversation_id', '=', 'messages.conversation_id')->whereColumn('p.user_id', '!=', 'messages.user_id'))
            ->whereIn('messages.user_id', self::newHumans())
            ->where('messages.created_at', '>=', now()->subDay())
            ->groupBy('messages.user_id')
            ->havingRaw('count(distinct p.user_id) >= ?', [self::DM_PEOPLE])
            ->get(['messages.user_id', DB::raw('count(distinct p.user_id) as people')]);

        if ($senders->isNotEmpty()) {
            self::record('dm_blast', 'dm:'.now()->toDateString(), $senders->count().' new '.str('account')->plural($senders->count()).' messaged '.self::DM_PEOPLE.'+ people in a day',
                $senders->map(fn ($s) => (object) ['user_id' => (int) $s->user_id, 'id' => null, 'body' => 'Messaged '.$s->people.' people in a day', 'created_at' => now()]));
        }
    }

    /**
     * Hide every visible post from the cluster's members and put them in a timeout. Never bans:
     * bans stay a one-person decision. Staff, house accounts and the moderator are skipped.
     *
     * @return array{hidden: int, timeouts: int}
     */
    public static function act(SpamCluster $cluster, User $moderator, int $hours = 24): array
    {
        abort_unless($cluster->status === 'open', 422, 'This cluster was already handled.');
        abort_unless(isset(Penalty::TIMEOUTS[$hours]), 422);

        $members = User::whereIn('id', $cluster->members)->where('is_staff', false)->where('is_bot', false)->whereKeyNot($moderator->id)->get();
        $hidden = Dispatch::whereIn('user_id', $members->pluck('id'))->whereIn('status', ['approved', 'pending'])->update(['status' => 'hidden']);

        $timeouts = 0;
        foreach ($members as $member) {
            if (! $member->activeBan() && ! $member->activeTimeout()) {
                Moderate::penalize($member, (string) $hours, self::RULE, null, $moderator);
                $timeouts++;
            }
        }

        $cluster->forceFill(['status' => 'actioned', 'actioned_by' => $moderator->id, 'actioned_at' => now()])->save();
        Audit::log('spam_cluster.actioned', $cluster, ['hidden' => $hidden, 'timeouts' => $timeouts, 'hours' => $hours]);

        return ['hidden' => $hidden, 'timeouts' => $timeouts];
    }

    public static function dismiss(SpamCluster $cluster): void
    {
        $cluster->forceFill(['status' => 'dismissed', 'actioned_at' => now()])->save();
        Audit::log('spam_cluster.dismissed', $cluster);
    }

    /**
     * Open clusters an account is in, for its admin page.
     *
     * @return Collection<int, SpamCluster>
     */
    public static function for(User $user): Collection
    {
        return SpamCluster::where('status', 'open')->get()->filter(fn ($c) => in_array($user->id, $c->members, true))->values();
    }

    /**
     * Lowercase words only, so punctuation, case and spacing don't hide a copy.
     */
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * Hashes of every three-word phrase, or none for posts too short to judge.
     *
     * @return array<int, int>
     */
    public static function shingles(string $text): array
    {
        $words = explode(' ', self::normalize($text));
        if (count($words) < self::MIN_WORDS) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($i) => crc32(implode(' ', array_slice($words, $i, 3))), range(0, count($words) - 3))));
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union ? count(array_intersect($a, $b)) / $union : 0.0;
    }

    /**
     * @return array<int, string>
     */
    public static function domains(string $text): array
    {
        preg_match_all('~\b(?:https?://|www\.)([a-z0-9.-]+\.[a-z]{2,})~i', $text, $m);

        return array_values(array_unique(array_map(fn ($host) => preg_replace('/^www\./', '', strtolower($host)), $m[1])));
    }

    /**
     * Posts by people (not staff or house accounts), optionally only from new accounts.
     */
    protected static function humanPosts(bool $newOnly = false): \Illuminate\Database\Eloquent\Builder
    {
        return Dispatch::query()->whereIn('dispatches.user_id', $newOnly ? self::newHumans() : self::humans())->where('dispatches.status', '!=', 'removed');
    }

    protected static function humans(): Builder
    {
        return DB::table('users')->where('is_staff', false)->where('is_bot', false)->select('id');
    }

    protected static function newHumans(): Builder
    {
        return self::humans()->where('created_at', '>=', now()->subDays(self::NEW_DAYS));
    }

    /**
     * Saves a cluster, or adds to an open one with the same signature. Dismissed or handled
     * clusters stay closed.
     *
     * @param  Collection<int, object>  $posts
     */
    protected static function record(string $kind, string $signature, string $summary, Collection $posts): void
    {
        $cluster = SpamCluster::firstOrNew(['kind' => $kind, 'signature' => $signature]);
        if ($cluster->exists && $cluster->status !== 'open') {
            return;
        }

        $names = User::whereIn('id', $posts->pluck('user_id'))->pluck('username', 'id');
        $cluster->fill([
            'summary' => $summary,
            'members' => array_values(array_unique([...($cluster->members ?? []), ...$posts->pluck('user_id')->map(fn ($id) => (int) $id)->all()])),
            'evidence' => $posts->take(25)->map(fn ($p) => [
                'user_id' => (int) $p->user_id,
                'username' => $names[$p->user_id] ?? null,
                'dispatch_id' => $p->id,
                'text' => str($p->body)->limit(140)->toString(),
                'at' => (string) $p->created_at,
            ])->values()->all(),
            'status' => 'open',
        ])->save();
    }
}
