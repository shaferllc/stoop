<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\CatchUp;
use App\Support\Claude;
use App\Support\ComposeHints;
use App\Support\Neighborhoods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ward, ANC and 311 for a station, "Catch me up", and composer suggestions.
 */
class NeighborhoodController extends Controller
{
    public function station(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $code = config("metro.aliases.$code", $code);
        abort_unless(config("stoop.stations.$code"), 404);
        $anc = Neighborhoods::anc($code);

        return response()->json(['data' => [
            ...Present::stop($code),
            'ward' => Neighborhoods::ward($code),
            'anc' => $anc,
            'anc_url' => $anc ? Neighborhoods::ancUrl($anc) : null,
            'service_requests' => $anc ? Neighborhoods::serviceRequests($code) : null,
            'pepco_outage_map' => Neighborhoods::pepco($code) ? config('neighborhoods.pepco_outage_map') : null,
        ]]);
    }

    public function catchUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stop' => ['required_without:line', 'nullable', Rule::in(array_keys(config('stoop.stations')))],
            'line' => ['required_without:stop', 'nullable', Rule::in(array_keys(config('stoop.lines')))],
        ]);
        $digest = CatchUp::for($data['stop'] ?? null, $data['line'] ?? null);

        return response()->json(['data' => [
            'ai' => $digest['ai'],
            'bullets' => $digest['bullets'],
            'posts' => $digest['posts']->map(fn ($d) => ['id' => $d->id, 'body' => $d->body, 'replies' => (int) $d->replies_count, 'author' => Present::user($d->user)])->values(),
        ]]);
    }

    /**
     * Suggested line, type and stop for a draft. Keyword guesses, or Claude's when refine=1
     * and a key is set. The app shows them as chips; nothing is applied.
     */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.config('stoop.max_length')], 'refine' => ['boolean']]);
        $refined = ($data['refine'] ?? false) ? ComposeHints::refine($data['body']) : null;

        return response()->json(['data' => [
            ...($refined ?? ComposeHints::guess($data['body'])),
            'source' => $refined ? 'claude' : 'keywords',
            'claude_available' => Claude::enabled(),
        ]]);
    }
}
