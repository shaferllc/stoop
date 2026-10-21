<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metro Bingo: a weekly 5×5 card of DC things to do, the same all week for each person and
 * different from their neighbors'. Squares Stoop can see in your activity mark themselves;
 * the rest you mark with one of this week's posts as proof. Weeks run Monday to Sunday, DC
 * time, like rivalries. Only hand-marked squares are stored.
 */
class Bingo
{
    public const FREE = 'free';

    /** Squares with true mark themselves from activity; false ones need a post as proof. */
    public const SQUARES = [
        'line-red' => ['Post from a Red Line stop', true],
        'line-orange' => ['Post from an Orange Line stop', true],
        'line-blue' => ['Post from a Blue Line stop', true],
        'line-green' => ['Post from a Green Line stop', true],
        'line-yellow' => ['Post from a Yellow Line stop', true],
        'line-silver' => ['Post from a Silver Line stop', true],
        'answer-ask' => ['Answer an Ask DC', true],
        'been-here' => ['File a Been here', true],
        'list-spot' => ['Go to a spot on a magazine list', true],
        'happening' => ['Post a Happening', true],
        'rsvp' => ['RSVP Going to a Happening', true],
        'poll-vote' => ['Vote in a poll', true],
        'photo' => ['Post a photo', true],
        'daily' => ['Play The Daily', true],
        'half-smoke' => ['Give a half-smoke', true],
        'mumbo' => ['Give some mumbo sauce', true],
        'follow' => ['Follow a new neighbor', true],
        'east-river' => ['Post from east of the river', true],
        'three-stops' => ['Post from 3 different stops', true],
        'eat-half-smoke' => ['Eat a half-smoke', false],
        'go-go' => ['Catch some go-go', false],
        'bikeshare' => ['Ride a Capital Bikeshare', false],
        'museum' => ['Visit a free museum', false],
        'escalator' => ['Stand right on a Metro escalator', false],
        'mumbo-wings' => ['Order wings with mumbo sauce', false],
        'market' => ['Shop a farmers market', false],
        'mall' => ['Walk the National Mall', false],
        'jumbo-slice' => ['Eat a jumbo slice', false],
        'rock-creek' => ['Get outside in Rock Creek Park', false],
        'anc' => ['Go to an ANC meeting', false],
    ];

    public const XP = 50;

    public static function weekStart(?CarbonImmutable $at = null): CarbonImmutable
    {
        return Rivalries::weekStart($at);
    }

    /**
     * The 25 square keys for this person's card, row by row, with the free square in the middle.
     *
     * @return array<int, string>
     */
    public static function layout(User $user, CarbonImmutable $week): array
    {
        $keys = array_keys(self::SQUARES);
        $seed = $user->id.':'.$week->toDateString();
        usort($keys, fn ($a, $b) => crc32($seed.$a) <=> crc32($seed.$b));
        $keys = array_slice($keys, 0, 24);
        array_splice($keys, 12, 0, [self::FREE]);

        return $keys;
    }

    /**
     * The card with what's marked, and how.
     *
     * @return array{week: CarbonImmutable, squares: array<int, array{key: string, label: string, auto: bool, marked: bool, proof: ?int}>, lines: int, played: bool}
     */
    public static function card(User $user, ?CarbonImmutable $week = null): array
    {
        $week ??= self::weekStart();
        $auto = self::detected($user, $week);
        $manual = self::manual($user, $week);

        $squares = array_map(fn ($key) => [
            'key' => $key,
            'label' => $key === self::FREE ? 'Free: you’re on Stoop' : self::SQUARES[$key][0],
            'auto' => $key === self::FREE || self::SQUARES[$key][1],
            'marked' => $key === self::FREE || in_array($key, $auto, true) || isset($manual[$key]),
            'proof' => $manual[$key] ?? null,
        ], self::layout($user, $week));

        return ['week' => $week, 'squares' => $squares, 'lines' => self::lines(array_column($squares, 'marked')), 'played' => $manual !== []];
    }

    /**
     * Mark a hand-marked square with one of your posts from this week as proof.
     * Returns why it didn't work, or null.
     */
    public static function mark(User $user, string $square, int $dispatchId): ?string
    {
        $week = self::weekStart();

        if (! in_array($square, self::layout($user, $week), true) || (self::SQUARES[$square][1] ?? true)) {
            return 'That square isn’t one you mark by hand this week.';
        }

        $proof = self::proofPosts($user, $week)->firstWhere('id', $dispatchId);
        if (! $proof) {
            return 'Pick one of your posts from this week as proof.';
        }

        DB::table('bingo_marks')->updateOrInsert(
            ['user_id' => $user->id, 'week' => $week->toDateString(), 'square' => $square],
            ['dispatch_id' => $proof->id, 'created_at' => now(), 'updated_at' => now()],
        );

        return null;
    }

    /**
     * Your approved posts from a week, to use as proof.
     *
     * @return Collection<int, Dispatch>
     */
    public static function proofPosts(User $user, CarbonImmutable $week)
    {
        return $user->dispatches()->where('status', 'approved')
            ->whereBetween('created_at', [$week->utc(), $week->addWeek()->utc()])->latest()->get(['id', 'body', 'created_at']);
    }

    /**
     * How many weeks someone got a bingo. A week counts once you've played it, by marking at
     * least one square by hand, so posting alone never wins it. Finished weeks are cached for good.
     */
    public static function wins(User $user): int
    {
        $current = self::weekStart();

        return DB::table('bingo_marks')->where('user_id', $user->id)->distinct()->pluck('week')
            ->map(fn ($w) => CarbonImmutable::parse($w, config('stoop.timezone'))->startOfDay())
            ->filter(fn ($week) => $week->eq($current)
                ? self::card($user, $week)['lines'] > 0
                : Cache::rememberForever("stoop:bingo:{$user->id}:{$week->toDateString()}", fn () => self::card($user, $week)['lines'] > 0))
            ->count();
    }

    /**
     * Completed rows, columns and diagonals.
     *
     * @param  array<int, bool>  $marked
     */
    public static function lines(array $marked): int
    {
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = range($i * 5, $i * 5 + 4);
            $lines[] = range($i, 24, 5);
        }
        $lines[] = [0, 6, 12, 18, 24];
        $lines[] = [4, 8, 12, 16, 20];

        return count(array_filter($lines, fn ($line) => ! in_array(false, array_map(fn ($i) => $marked[$i], $line), true)));
    }

    /**
     * Hand-marked squares whose proof post is still up.
     *
     * @return array<string, int>
     */
    protected static function manual(User $user, CarbonImmutable $week): array
    {
        return DB::table('bingo_marks')->join('dispatches', 'dispatches.id', '=', 'bingo_marks.dispatch_id')
            ->where('bingo_marks.user_id', $user->id)->where('bingo_marks.week', $week->toDateString())
            ->where('dispatches.user_id', $user->id)->where('dispatches.status', 'approved')->whereNull('dispatches.deleted_at')
            ->pluck('bingo_marks.dispatch_id', 'bingo_marks.square')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Squares this person's activity in the week already covers.
     *
     * @return array<int, string>
     */
    protected static function detected(User $user, CarbonImmutable $week): array
    {
        $window = [$week->utc(), $week->addWeek()->utc()];
        $posts = DB::table('dispatches')->where('user_id', $user->id)->where('status', 'approved')->whereNull('deleted_at')
            ->whereBetween('created_at', $window)->get(['id', 'parent_id', 'type', 'stop', 'place_id']);
        $stops = $posts->pluck('stop')->filter()->unique();
        $ids = $posts->pluck('id');
        $marks = DB::table('marks')->where('user_id', $user->id)->whereBetween('created_at', $window)
            ->whereNotIn('dispatch_id', $ids)->distinct()->pluck('kind')->all();

        $found = [
            'answer-ask' => DB::table('dispatches as p')->whereIn('p.id', $posts->pluck('parent_id')->filter())
                ->where('p.type', 'ask')->where('p.user_id', '!=', $user->id)->exists(),
            'been-here' => $posts->contains('type', 'been'),
            'list-spot' => $posts->where('type', 'been')->isNotEmpty()
                && DB::table('places')->whereIn('id', $posts->where('type', 'been')->pluck('place_id'))->whereNotNull('list_name')->exists(),
            'happening' => $posts->contains(fn ($p) => $p->type === 'happening' && ! $p->parent_id),
            'rsvp' => in_array('going', $marks, true),
            'mumbo' => in_array('mumbo', $marks, true),
            'poll-vote' => DB::table('poll_votes')->where('user_id', $user->id)->whereBetween('created_at', $window)->exists(),
            'photo' => DB::table('dispatch_images')->whereIn('dispatch_id', $ids)->exists(),
            'daily' => DB::table('quiz_attempts')->where('user_id', $user->id)->whereBetween('completed_at', $window)->exists(),
            'half-smoke' => DB::table('half_smokes')->where('giver_id', $user->id)->whereBetween('created_at', $window)->exists(),
            'follow' => DB::table('follows')->where('follower_id', $user->id)->whereBetween('created_at', $window)->exists(),
            'east-river' => $stops->contains(fn ($code) => Stations::eastOfTheRiver($code)),
            'three-stops' => $stops->count() >= 3,
        ];
        foreach (array_keys(config('metro.lines')) as $line) {
            $found["line-$line"] = $stops->contains(fn ($code) => in_array($line, Stations::linesAt($code), true));
        }

        return array_keys(array_filter($found));
    }
}
