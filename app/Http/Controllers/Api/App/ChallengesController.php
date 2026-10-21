<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\Place;
use App\Support\Challenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * List challenges on the magazine's lists, with the signed-in person's progress.
 */
class ChallengesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $challenges = Challenge::where('ends_at', '>=', now()->subMonths(3))->orderBy('starts_at')->get();

        return response()->json(['data' => $challenges->map(fn (Challenge $c) => [
            ...$this->summary($c),
            'progress' => Challenges::progress($c, $request->user())['count'],
        ])]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $challenge = Challenge::where('slug', $slug)->firstOrFail();
        Challenges::check($request->user());
        $progress = Challenges::progress($challenge, $request->user());
        $checked = array_flip($progress['place_ids']);

        return response()->json(['data' => [
            ...$this->summary($challenge),
            'progress' => $progress['count'],
            'finished_at' => $progress['finished_at'],
            'places' => Place::where('list_name', $challenge->list_name)->orderBy('list_rank')->orderBy('name')->get(['id', 'name', 'slug', 'stop', 'neighborhood'])
                ->map(fn (Place $p) => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'stop' => $p->stop, 'neighborhood' => $p->neighborhood, 'been' => isset($checked[$p->id])]),
            'leaderboard' => Challenges::leaderboard($challenge)->map(fn ($r) => ['username' => $r->username, 'count' => $r->count]),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Challenge $c): array
    {
        return [
            'slug' => $c->slug, 'title' => $c->title, 'list' => $c->list_name, 'goal' => $c->goal,
            'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(),
            'running' => $c->isRunning(), 'prize' => $c->prize, 'badge' => $c->badge_name, 'url' => route('challenges.show', $c),
        ];
    }
}
