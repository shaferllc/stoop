<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Hunt;
use App\Support\Bingo;
use App\Support\Rivalries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Metro Bingo, scavenger hunts and the Line Cup. Hunt answers and code words never leave
 * the server.
 */
class GamesController extends Controller
{
    public function bingo(Request $request): JsonResponse
    {
        $card = Bingo::card($request->user());

        return response()->json(['data' => [
            'week' => $card['week']->toDateString(),
            'squares' => $card['squares'],
            'lines' => $card['lines'],
            'xp' => Bingo::XP,
        ]]);
    }

    /**
     * Mark a hand-marked square with one of this week's posts as proof.
     */
    public function markBingo(Request $request): JsonResponse
    {
        $data = $request->validate(['square' => ['required', 'string', 'max:40'], 'dispatch_id' => ['required', 'integer']]);

        if ($reason = Bingo::mark($request->user(), $data['square'], $data['dispatch_id'])) {
            return response()->json(['message' => $reason, 'errors' => ['square' => [$reason]]], 422);
        }

        return $this->bingo($request);
    }

    public function hunts(): JsonResponse
    {
        $hunts = Hunt::withCount('steps')->where('ends_at', '>=', now()->subMonths(3))->orderBy('starts_at')->get();

        return response()->json(['data' => $hunts->map(fn (Hunt $h) => [
            'slug' => $h->slug, 'name' => $h->name, 'steps' => $h->steps_count, 'running' => $h->isRunning(),
            'starts_at' => $h->starts_at->toIso8601String(), 'ends_at' => $h->ends_at->toIso8601String(),
        ])]);
    }

    public function hunt(Request $request, string $slug): JsonResponse
    {
        $hunt = Hunt::with('steps')->where('slug', $slug)->firstOrFail();
        $done = DB::table('hunt_completions')->where('user_id', $request->user()->id)
            ->whereIn('hunt_step_id', $hunt->steps->pluck('id'))->pluck('created_at', 'hunt_step_id');

        return response()->json(['data' => [
            'slug' => $hunt->slug,
            'name' => $hunt->name,
            'description' => $hunt->description,
            'running' => $hunt->isRunning(),
            'starts_at' => $hunt->starts_at->toIso8601String(),
            'ends_at' => $hunt->ends_at->toIso8601String(),
            // Clues show once the hunt starts.
            'steps' => $hunt->starts_at->isFuture() ? [] : $hunt->steps->map(fn ($s) => [
                'id' => $s->id, 'position' => $s->position, 'clue' => $s->clue,
                'needs_code_word' => (bool) $s->code_word, 'found_at' => $done[$s->id] ?? null,
            ]),
            'leaderboard' => $hunt->leaderboard()->map(fn ($r) => ['user' => Present::user($r->user), 'steps' => $r->steps]),
        ]]);
    }

    public function checkIn(Request $request, string $slug, int $stepId): JsonResponse
    {
        $hunt = Hunt::where('slug', $slug)->firstOrFail();
        $step = $hunt->steps()->findOrFail($stepId);

        if ($reason = $step->complete($request->user(), $request->string('code_word')->toString() ?: null)) {
            return response()->json(['message' => $reason, 'errors' => ['code_word' => [$reason]]], 422);
        }

        return response()->json(['message' => 'Found it! Your passport has a new stamp.']);
    }

    public function lineCup(): JsonResponse
    {
        $present = fn ($cup) => $cup->map(fn ($row, $line) => [
            'line' => $line, 'name' => config("stoop.lines.$line.name"), 'color' => config("stoop.lines.$line.color"),
            'points' => $row['points'], 'people' => $row['people'], 'regulars' => count($row['regulars']),
        ])->values();

        return response()->json(['data' => [
            'month' => Rivalries::monthStart()->format('Y-m'),
            'current' => $present(Rivalries::lineCup(Rivalries::monthStart())),
            'last' => $present(Rivalries::lineCup(Rivalries::monthStart()->subMonthNoOverflow())),
            'winners' => Rivalries::cupWinners(6)->map(fn ($w) => ['month' => $w['month']->format('Y-m'), 'line' => $w['line'], 'points' => $w['points']]),
        ]]);
    }
}
