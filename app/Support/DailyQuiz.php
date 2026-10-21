<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily DC quiz: five questions a day, the same five for everyone, cycling through
 * the whole bank before any question repeats.
 */
class DailyQuiz
{
    public const PER_DAY = 5;

    protected const EPOCH = '2026-01-01';

    // Correct answers show in Metro line colors, in this order; misses are blank.
    protected const SQUARES = ['🟥', '🟧', '🟦', '🟩', '🟨'];

    public static function today(): CarbonImmutable
    {
        return now(config('stoop.timezone'))->toImmutable()->startOfDay();
    }

    public static function number(CarbonImmutable $day): int
    {
        return (int) CarbonImmutable::parse(self::EPOCH)->diffInDays($day) + 1;
    }

    /**
     * @return array<int, int>
     */
    public static function questionIdsFor(CarbonImmutable $day): array
    {
        $ids = DB::table('quiz_questions')->where('active', true)->orderBy('id')->pluck('id')->all();

        if (count($ids) < self::PER_DAY) {
            return $ids;
        }

        // Shuffle the bank the same way for everyone within a cycle, then take today's slice.
        $start = (self::number($day) - 1) * self::PER_DAY;
        $cycle = intdiv($start, count($ids));
        mt_srand(crc32('stoop-quiz-'.$cycle));
        shuffle($ids);
        mt_srand();

        $offset = $start % count($ids);
        $slice = array_slice($ids, $offset, self::PER_DAY);

        return count($slice) === self::PER_DAY ? $slice : array_merge($slice, array_slice($ids, 0, self::PER_DAY - count($slice)));
    }

    /**
     * @return Collection<int, object>
     */
    public static function questions(array $ids): Collection
    {
        $questions = DB::table('quiz_questions')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $questions[$id] ?? null)->filter()
            ->each(fn ($q) => $q->choices = json_decode($q->choices, true))->values();
    }

    public static function attempt(User $user, CarbonImmutable $day): object
    {
        $attempt = DB::table('quiz_attempts')->where(['user_id' => $user->id, 'day' => $day->toDateString()])->first();

        if (! $attempt) {
            DB::table('quiz_attempts')->insertOrIgnore([
                'user_id' => $user->id, 'day' => $day->toDateString(),
                'question_ids' => json_encode(self::questionIdsFor($day)), 'answers' => json_encode([]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $attempt = DB::table('quiz_attempts')->where(['user_id' => $user->id, 'day' => $day->toDateString()])->first();
        }

        $attempt->question_ids = json_decode($attempt->question_ids, true);
        $attempt->answers = json_decode($attempt->answers, true);

        return $attempt;
    }

    /**
     * Record the answer to the next unanswered question. Answers can't be changed.
     */
    public static function answer(User $user, int $choice): object
    {
        $attempt = self::attempt($user, self::today());
        $index = count($attempt->answers);

        if ($index >= count($attempt->question_ids)) {
            return $attempt;
        }

        $question = DB::table('quiz_questions')->find($attempt->question_ids[$index]);
        $attempt->answers[] = ['choice' => $choice, 'correct' => $question && $question->answer === $choice];
        $done = count($attempt->answers) >= count($attempt->question_ids);
        $score = collect($attempt->answers)->where('correct', true)->count();

        DB::table('quiz_attempts')->where('id', $attempt->id)->update([
            'answers' => json_encode($attempt->answers), 'score' => $score,
            'completed_at' => $done ? now() : null, 'updated_at' => now(),
        ]);

        return self::attempt($user, self::today());
    }

    /**
     * @return array{current: int, longest: int, pass_used_this_week: bool}
     */
    public static function streaks(User $user): array
    {
        return Streaks::compute(DB::table('quiz_attempts')->where('user_id', $user->id)->whereNotNull('completed_at')->pluck('day')->map(fn ($d) => substr($d, 0, 10))->all());
    }

    public static function shareText(object $attempt): string
    {
        $squares = collect($attempt->answers)->map(fn ($a, $i) => $a['correct'] ? self::SQUARES[$i % 5] : '⬜')->join('');
        $score = collect($attempt->answers)->where('correct', true)->count();

        return 'Stoop Daily #'.self::number(CarbonImmutable::parse($attempt->day))." {$score}/".count($attempt->question_ids)."\n{$squares}\n".route('quiz');
    }

    public static function correctAnswers(User $user): int
    {
        return (int) DB::table('quiz_attempts')->where('user_id', $user->id)->sum('score');
    }
}
