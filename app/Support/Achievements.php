<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Badges and XP, worked out from what someone has actually done. Nothing is stored, so
 * removing activity can lock a badge again.
 */
class Achievements
{
    public const BADGES = [
        'doors-open' => ['name' => 'Doors Open', 'description' => 'Filed your first dispatch'],
        'neighbor' => ['name' => 'Neighbor', 'description' => 'Replied to 5 other people'],
        'regular' => ['name' => 'Regular', 'description' => 'Posted 7 days in a row'],
        'local' => ['name' => 'Local', 'description' => 'Posted 30 days in a row'],
        'lifer' => ['name' => 'Lifer', 'description' => 'Posted 100 days in a row'],
        'all-lines' => ['name' => 'All Lines', 'description' => 'Posted on every Metro line'],
        'station-hopper' => ['name' => 'Station Hopper', 'description' => 'Tagged 10 different stations'],
        'critic' => ['name' => 'Critic', 'description' => 'Filed 10 Been here posts'],
        'pollster' => ['name' => 'Pollster', 'description' => 'Ran 3 polls'],
        'big-night' => ['name' => 'Big Night Out', 'description' => 'Posted a Happening 10 people went to'],
        'talk-of-the-town' => ['name' => 'Talk of the Town', 'description' => 'Got 25 replies on one dispatch'],
        'half-smoke-hall' => ['name' => 'Half-Smoke Hall of Fame', 'description' => 'Received 25 half-smokes'],
        'line-red' => ['name' => 'Rode the Red Line', 'description' => 'Posted from every Red Line station'],
        'line-orange' => ['name' => 'Rode the Orange Line', 'description' => 'Posted from every Orange Line station'],
        'line-blue' => ['name' => 'Rode the Blue Line', 'description' => 'Posted from every Blue Line station'],
        'line-green' => ['name' => 'Rode the Green Line', 'description' => 'Posted from every Green Line station'],
        'line-yellow' => ['name' => 'Rode the Yellow Line', 'description' => 'Posted from every Yellow Line station'],
        'line-silver' => ['name' => 'Rode the Silver Line', 'description' => 'Posted from every Silver Line station'],
        'whole-system' => ['name' => 'The Whole System', 'description' => 'Posted from all 98 Metro stations'],
        'crossed-the-river' => ['name' => 'Crossed the River', 'description' => 'Posted from a station east of the Anacostia'],
        'ward-explorer' => ['name' => 'Ward Explorer', 'description' => 'Posted from stations in all 8 DC wards'],
        'tri-state' => ['name' => 'DMV', 'description' => 'Posted from stations in DC, Maryland and Virginia'],
        'know-it-all' => ['name' => 'Know-It-All', 'description' => 'Gave 5 Best Answers on Ask DC'],
        'passport' => ['name' => 'Stamped', 'description' => 'Earned a passport stamp for one of the magazine’s lists'],
        'quiz-week' => ['name' => 'Regular Reader', 'description' => 'Played The Daily 7 days in a row'],
        'quiz-perfect' => ['name' => 'Native Knowledge', 'description' => 'Got a perfect score on The Daily'],
        'called-it' => ['name' => 'Called It', 'description' => 'Picked the winner in 5 game-day threads'],
        'snow-reporter' => ['name' => 'Snow Reporter', 'description' => 'Filed a snow report on a Snow Day'],
        'stoop-sits' => ['name' => 'Stoop Sitter', 'description' => 'Checked in at a Stoop Sits meetup'],
        'out-and-about' => ['name' => 'Out and About', 'description' => 'Posted from 3 city events'],
        'stand-right' => ['name' => 'Stand Right, Walk Left', 'description' => 'Posted about a Metro escalator'],
        'sauced' => ['name' => 'Sauced', 'description' => 'Got 10 mumbo sauces on your dispatches'],
        'mayor' => ['name' => 'Mayor', 'description' => 'The most helpful person at a stop this month'],
        'bingo' => ['name' => 'Metro Bingo', 'description' => 'Got a bingo on a weekly Metro Bingo card'],
        'hunter' => ['name' => 'Treasure Hunter', 'description' => 'Found every clue in a scavenger hunt'],
        'line-cup' => ['name' => 'Line Cup', 'description' => 'A regular on the line that won a monthly Line Cup'],
    ];

    // Like /dev/null's Unix ranks, named for how far into the city you are.
    public const RANKS = [0 => 'Tourist', 100 => 'Commuter', 500 => 'Regular', 1500 => 'Local', 4000 => 'Block Captain', 10000 => 'Mayor'];

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public static function unlocked(User $user): array
    {
        $s = self::stats($user);

        $earned = [
            'doors-open' => $s['dispatches'] >= 1,
            'neighbor' => $s['replies_to_others'] >= 5,
            'regular' => $s['longest_streak'] >= 7,
            'local' => $s['longest_streak'] >= 30,
            'lifer' => $s['longest_streak'] >= 100,
            'all-lines' => $s['lines'] >= count(config('stoop.lines')),
            'station-hopper' => $s['stations'] >= 10,
            'critic' => $s['been_posts'] >= 10,
            'pollster' => $s['polls'] >= 3,
            'big-night' => $s['best_happening'] >= 10,
            'talk-of-the-town' => $s['most_replies'] >= 25,
            'half-smoke-hall' => $s['half_smokes_received'] >= 25,
            'whole-system' => count($s['visited']) >= count(config('metro.stations')),
            'crossed-the-river' => collect($s['visited'])->contains(fn ($code) => Stations::eastOfTheRiver($code)),
            'tri-state' => count(array_unique(array_map(fn ($code) => Stations::jurisdiction($code), $s['visited']))) >= 3,
            'ward-explorer' => count(array_unique(array_filter(array_map(fn ($code) => Neighborhoods::ward($code), $s['visited'])))) >= 8,
            'mayor' => Stations::mayorships($user) !== [],
            'know-it-all' => $s['best_answers'] >= 5,
            'quiz-week' => $s['quiz_longest'] >= 7,
            'quiz-perfect' => $s['quiz_perfect'] > 0,
            'called-it' => $s['right_picks'] >= 5,
            'stand-right' => $s['escalator_posts'] > 0,
            'sauced' => $s['mumbo_received'] >= 10,
            'snow-reporter' => $s['snow_reports'] > 0,
            'stoop-sits' => $s['meetups'] > 0,
            'out-and-about' => $s['events'] >= 3,
            'passport' => $s['stamps'] >= 1,
            'bingo' => $s['bingos'] > 0,
            'hunter' => $s['hunts_finished'] > 0,
            'line-cup' => $s['cup_crowns'] > 0,
        ];

        foreach (config('metro.lines') as $line => $stops) {
            $earned["line-$line"] = array_diff($stops, $s['visited']) === [];
        }

        return array_intersect_key(self::BADGES, array_filter($earned));
    }

    /**
     * 10 per dispatch, 15 per day of the longest streak, 5 per Been here/Going/Save received,
     * 5 per reply to someone else, 2 per mark given to someone else, 25 per Best Answer
     * 100 per passport stamp, 2 per right answer on The Daily, 10 per right game-day pick,
     * 20 per meetup check-in, 50 per Metro Bingo week, 25 per scavenger hunt stamp and 100 per
     * Line Cup crown.
     */
    public static function xp(User $user): int
    {
        $s = self::stats($user);

        return $s['dispatches'] * 10 + $s['longest_streak'] * 15 + $s['marks_received'] * 5
            + $s['replies_to_others'] * 5 + $s['marks_given'] * 2 + $s['best_answers'] * 25 + $s['stamps'] * 100 + $s['quiz_correct'] * 2
            + $s['right_picks'] * 10 + $s['meetups'] * 20
            + $s['bingos'] * Bingo::XP + $s['hunt_steps'] * 25 + $s['cup_crowns'] * 100;
    }

    public static function rank(int $xp): string
    {
        return collect(self::RANKS)->filter(fn ($name, $min) => $xp >= $min)->last();
    }

    /**
     * "On Time" posting streak, with one forgiven day a week (see Streaks).
     *
     * @return array{current: int, longest: int, pass_used_this_week: bool}
     */
    public static function streaks(User $user): array
    {
        return Streaks::compute(self::activeDays($user));
    }

    /**
     * Dispatch counts per DC calendar day, for the ridership graph.
     *
     * @return array<string, int>
     */
    public static function dailyCounts(User $user, int $days = 371): array
    {
        return $user->dispatches()->where('status', 'approved')
            ->where('created_at', '>=', now()->subDays($days))
            ->pluck('created_at')
            ->countBy(fn ($at) => $at->timezone(config('stoop.timezone'))->toDateString())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function activeDays(User $user): array
    {
        return $user->dispatches()->where('status', 'approved')->pluck('created_at')
            ->map(fn ($at) => $at->timezone(config('stoop.timezone'))->toDateString())
            ->unique()->sort()->values()->all();
    }

    /**
     * @return array<string, int>
     */
    protected static function stats(User $user): array
    {
        return once(function () use ($user) {
            $approved = $user->dispatches()->where('status', 'approved');
            $mine = (clone $approved)->select('id');

            return [
                'dispatches' => (clone $approved)->count(),
                'replies_to_others' => (clone $approved)->whereNotNull('parent_id')
                    ->whereHas('parent', fn ($q) => $q->where('user_id', '!=', $user->id))->count(),
                'longest_streak' => self::streaks($user)['longest'],
                'lines' => (clone $approved)->whereNull('parent_id')->distinct()->count('line'),
                'stations' => (clone $approved)->whereNotNull('stop')->distinct()->count('stop'),
                'been_posts' => (clone $approved)->where('type', 'been')->count(),
                'polls' => (clone $approved)->where('type', 'poll')->count(),
                'best_happening' => (int) DB::table('marks')->where('kind', 'going')
                    ->whereIn('dispatch_id', (clone $approved)->where('type', 'happening')->select('id'))
                    ->where('user_id', '!=', $user->id)
                    ->groupBy('dispatch_id')->selectRaw('count(*) as c')->orderByDesc('c')->value('c'),
                'most_replies' => (int) DB::table('dispatches')->whereIn('parent_id', $mine)->where('status', 'approved')
                    ->groupBy('parent_id')->selectRaw('count(*) as c')->orderByDesc('c')->value('c'),
                'half_smokes_received' => DB::table('half_smokes')->whereIn('dispatch_id', $mine)->count(),
                'visited' => Stations::visited($user),
                'best_answers' => DB::table('dispatches as q')->join('dispatches as a', 'a.id', '=', 'q.best_answer_id')->where('a.user_id', $user->id)->count(),
                'stamps' => Passports::for($user)->whereNotNull('stamped_at')->count(),
                'quiz_correct' => DailyQuiz::correctAnswers($user),
                'quiz_longest' => DailyQuiz::streaks($user)['longest'],
                'right_picks' => DB::table('poll_votes')->join('games', 'games.dispatch_id', '=', 'poll_votes.dispatch_id')
                    ->where('poll_votes.user_id', $user->id)
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('games.winner', 'team')->whereColumn('poll_votes.poll_option_id', 'games.team_option_id'))
                        ->orWhere(fn ($q) => $q->where('games.winner', 'opponent')->whereColumn('poll_votes.poll_option_id', 'games.opponent_option_id')))
                    ->count(),
                'escalator_posts' => (clone $approved)->where('body', 'like', '%escalator%')->count(),
                'mumbo_received' => DB::table('marks')->whereIn('dispatch_id', $mine)->where('kind', 'mumbo')->count(),
                'snow_reports' => (clone $approved)->where('type', 'snow')->count(),
                'meetups' => DB::table('event_checkins')->where('user_id', $user->id)->count(),
                'events' => (clone $approved)->whereNotNull('event_id')->distinct()->count('event_id'),
                'quiz_perfect' => DB::table('quiz_attempts')->where('user_id', $user->id)->where('score', DailyQuiz::PER_DAY)->count(),
                'marks_received' => DB::table('marks')->whereIn('dispatch_id', $mine)->where('user_id', '!=', $user->id)->count(),
                'marks_given' => DB::table('marks')->where('user_id', $user->id)
                    ->whereNotIn('dispatch_id', $mine)->count(),
                'bingos' => Bingo::wins($user),
                'hunt_steps' => DB::table('hunt_completions')->where('user_id', $user->id)->count(),
                'hunts_finished' => DB::table('hunts')->whereRaw('(select count(*) from hunt_steps where hunt_steps.hunt_id = hunts.id) > 0')
                    ->whereRaw('(select count(*) from hunt_steps join hunt_completions on hunt_completions.hunt_step_id = hunt_steps.id where hunt_steps.hunt_id = hunts.id and hunt_completions.user_id = ?) = (select count(*) from hunt_steps where hunt_steps.hunt_id = hunts.id)', [$user->id])
                    ->count(),
                'cup_crowns' => count(Rivalries::cupCrowns($user)),
            ];
        });
    }
}
