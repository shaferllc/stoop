<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\DailyQuiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Daily: five DC questions, the same for everyone today. Answers to questions you
 * haven't reached yet never leave the server.
 */
class QuizController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request)]);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate(['choice' => ['required', 'integer', 'between:0,3']]);
        DailyQuiz::answer($request->user(), $data['choice']);

        return response()->json(['data' => $this->state($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(Request $request): array
    {
        $user = $request->user();
        $day = DailyQuiz::today();
        $attempt = DailyQuiz::attempt($user, $day);
        $answers = $attempt->answers;

        $questions = DailyQuiz::questions($attempt->question_ids)->values()->map(function ($q, $i) use ($answers) {
            $answered = isset($answers[$i]);

            return [
                'id' => $q->id,
                'question' => $q->question,
                'choices' => $q->choices,
                'answered' => $answered,
                'choice' => $answered ? $answers[$i]['choice'] : null,
                'correct' => $answered ? $answers[$i]['correct'] : null,
                'answer' => $answered ? $q->answer : null,
                'explanation' => $answered ? $q->explanation : null,
            ];
        });

        $done = DB::table('quiz_attempts')->where('day', $day->toDateString())->whereNotNull('completed_at');

        return [
            'number' => DailyQuiz::number($day),
            'questions' => $questions,
            'score' => collect($answers)->where('correct', true)->count(),
            'completed' => (bool) $attempt->completed_at,
            'share' => $attempt->completed_at ? DailyQuiz::shareText($attempt) : null,
            'streaks' => DailyQuiz::streaks($user),
            'players' => (clone $done)->count(),
            'average' => round((float) $done->avg('score'), 1),
        ];
    }
}
