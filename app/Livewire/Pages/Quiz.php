<?php

namespace App\Livewire\Pages;

use App\Support\DailyQuiz;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('The Daily')]
class Quiz extends Component
{
    public function choose(int $choice): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless($choice >= 0 && $choice <= 3, 422);

        DailyQuiz::answer(Auth::user(), $choice);
    }

    public function render(): View
    {
        $day = DailyQuiz::today();
        $user = Auth::user();
        $attempt = $user ? DailyQuiz::attempt($user, $day) : null;
        $ids = $attempt?->question_ids ?? DailyQuiz::questionIdsFor($day);

        return view('livewire.pages.quiz', [
            'number' => DailyQuiz::number($day),
            'questions' => DailyQuiz::questions($ids),
            'attempt' => $attempt,
            'streaks' => $user ? DailyQuiz::streaks($user) : null,
            'share' => $attempt?->completed_at ? DailyQuiz::shareText($attempt) : null,
            'players' => DB::table('quiz_attempts')->where('day', $day->toDateString())->whereNotNull('completed_at')->count(),
            'average' => round((float) DB::table('quiz_attempts')->where('day', $day->toDateString())->whereNotNull('completed_at')->avg('score'), 1),
        ]);
    }
}
