<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Game;
use App\Support\Sports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * DC sports: today's games, a team page, and the signed-in person's favorite teams.
 */
class SportsController extends Controller
{
    public function today(): JsonResponse
    {
        $endOfToday = now(config('stoop.timezone'))->endOfDay()->utc();

        return response()->json(['data' => [
            'today' => Sports::today()->map(fn (Game $g) => Sports::present($g))->values(),
            'week' => Game::whereNotNull('team_key')->whereBetween('starts_at', [$endOfToday, $endOfToday->copy()->addDays(7)])
                ->orderBy('starts_at')->get()->map(fn (Game $g) => Sports::present($g))->values(),
            'teams' => collect(Sports::teams())->map(fn ($t, $key) => $this->teamSummary($key, $t))->values(),
        ]]);
    }

    public function team(string $team): JsonResponse
    {
        abort_unless($config = Sports::teams()[$team] ?? null, 404);
        $games = Game::where('team_key', $team);

        return response()->json(['data' => [
            ...$this->teamSummary($team, $config),
            'upcoming' => (clone $games)->whereIn('status', ['pre', 'live'])->where('starts_at', '>', now()->subHours(5))->orderBy('starts_at')->take(10)->get()->map(fn (Game $g) => Sports::present($g))->values(),
            'results' => (clone $games)->where('status', 'final')->latest('starts_at')->take(10)->get()->map(fn (Game $g) => Sports::present($g))->values(),
        ]]);
    }

    public function favorites(Request $request): JsonResponse
    {
        return response()->json(['data' => ['teams' => Sports::favorites($request->user())]]);
    }

    public function setFavorites(Request $request): JsonResponse
    {
        $data = $request->validate(['teams' => ['present', 'array'], 'teams.*' => [Rule::in(array_keys(Sports::teams()))]]);

        return response()->json(['data' => ['teams' => Sports::setFavorites($request->user(), $data['teams'])]]);
    }

    /**
     * Makes one of your Happenings a watch party for a game.
     */
    public function watchParty(Request $request): JsonResponse
    {
        $data = $request->validate(['dispatch_id' => ['required', 'integer'], 'game_id' => ['required', 'integer']]);
        $happening = Dispatch::where('user_id', $request->user()->id)->findOrFail($data['dispatch_id']);

        if (! Sports::linkWatchParty($happening, $data['game_id'])) {
            return response()->json(['message' => 'That isn’t a Happening, or the game is over.', 'errors' => ['game_id' => ['That isn’t a Happening, or the game is over.']]], 422);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    protected function teamSummary(string $key, array $t): array
    {
        return [
            'key' => $key, 'name' => $t['name'], 'full' => $t['full'], 'league' => $t['league'], 'colors' => $t['colors'],
            'venue' => $t['venue'], 'stop' => $t['stop'], 'tickets' => $t['tickets'],
            'standing' => Sports::standings($key), 'fans' => once(fn () => Sports::fanCounts())[$key] ?? 0,
        ];
    }
}
