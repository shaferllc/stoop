<?php

namespace App\Livewire\Admin;

use App\Support\Audit;
use App\Support\DailyQuiz;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Quiz · Admin')]
class QuizQuestions extends Component
{
    use StaffOnly;

    protected const ROLE = 'editor';

    public string $question = '';

    /** @var array<int, string> */
    public array $choices = ['', '', '', ''];

    public int $answer = 0;

    public string $explanation = '';

    public function add(): void
    {
        $this->validate([
            'question' => ['required', 'string', 'max:300'],
            'choices.*' => ['required', 'string', 'max:120', 'distinct'],
            'answer' => ['required', 'integer', 'between:0,3'],
            'explanation' => ['nullable', 'string', 'max:500'],
        ], [], ['choices.*' => 'choice']);

        DB::table('quiz_questions')->insert([
            'question' => $this->question, 'choices' => json_encode(array_values($this->choices)), 'answer' => $this->answer,
            'explanation' => $this->explanation ?: null, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log('quiz.question_added', null, ['question' => $this->question]);
        $this->reset('question', 'choices', 'answer', 'explanation');
        Flux::toast('Added. It joins the rotation.');
    }

    public function toggle(int $id): void
    {
        DB::table('quiz_questions')->where('id', $id)->update(['active' => DB::raw('not active'), 'updated_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.admin.quiz-questions', [
            'questions' => DB::table('quiz_questions')->latest('id')->get()->each(fn ($q) => $q->choices = json_decode($q->choices, true)),
            'today' => DailyQuiz::questionIdsFor(DailyQuiz::today()),
        ]);
    }
}
