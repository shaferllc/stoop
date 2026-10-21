<?php

namespace Tests\Feature;

use App\Livewire\Pages\Quiz;
use App\Models\User;
use App\Support\DailyQuiz;
use App\Support\Streaks;
use Carbon\CarbonImmutable;
use Database\Seeders\QuizQuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class QuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_everyone_gets_the_same_five_and_no_repeats_until_the_bank_cycles(): void
    {
        $this->seed(QuizQuestionSeeder::class);
        $day = CarbonImmutable::parse('2026-10-20');

        $this->assertCount(5, DailyQuiz::questionIdsFor($day));
        $this->assertSame(DailyQuiz::questionIdsFor($day), DailyQuiz::questionIdsFor($day));

        $seen = [];
        foreach (range(0, 4) as $i) {
            $seen = array_merge($seen, DailyQuiz::questionIdsFor(CarbonImmutable::parse('2026-01-01')->addDays($i)));
        }
        $this->assertSame(25, count(array_unique($seen)), 'The first 5 days of a 28-question bank don’t repeat.');
    }

    public function test_playing_scores_answers_once_and_builds_a_shareable_result(): void
    {
        $this->seed(QuizQuestionSeeder::class);
        $user = User::factory()->create();
        $ids = DailyQuiz::questionIdsFor(DailyQuiz::today());
        $answers = DB::table('quiz_questions')->whereIn('id', $ids)->pluck('answer', 'id');

        $quiz = Livewire::actingAs($user)->test(Quiz::class);
        foreach ($ids as $i => $id) {
            $quiz->call('choose', $i === 2 ? ($answers[$id] + 1) % 4 : $answers[$id]);
        }
        $quiz->call('choose', 0);

        $attempt = DailyQuiz::attempt($user, DailyQuiz::today());
        $this->assertSame(4, (int) $attempt->score);
        $this->assertCount(5, $attempt->answers);
        $this->assertStringContainsString("4/5\n🟥🟧⬜🟩🟨", DailyQuiz::shareText($attempt));
        $quiz->assertSee('Copy result')->assertSee('Quiz streak');
    }

    public function test_one_missed_day_a_week_does_not_break_a_streak(): void
    {
        $today = CarbonImmutable::parse('2026-10-22'); // a Thursday
        $days = fn (array $offsets) => array_map(fn ($o) => $today->subDays($o)->toDateString(), $offsets);

        // Played every day for two weeks except last Saturday: the pass covers it.
        $this->assertSame(13, Streaks::compute($days(array_diff(range(0, 13), [5])), $today)['current']);
        $this->assertTrue(Streaks::compute($days([0, 1, 3]), $today)['pass_used_this_week']);

        // Two misses in the same week break it: Thursday, (Wednesday missed), Tuesday, (Monday missed).
        $this->assertSame(2, Streaks::compute($days([0, 2, 4]), $today)['current']);
    }
}
