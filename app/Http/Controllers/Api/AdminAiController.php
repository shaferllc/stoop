<?php

namespace App\Http\Controllers\Api;

use App\Actions\Moderate;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Audit;
use App\Support\ModerationAssist;
use App\Support\ModerationInsights;
use App\Support\WatchPhrases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Moderator help for the Stoop Admin app: Claude's suggestions, past decisions on similar
 * posts, person briefs, appeal notes, threshold insights and the watch-phrase list. Same
 * staff-and-admin-token check as AdminController.
 */
class AdminAiController extends AdminController
{
    /**
     * Extras for every queue item in one call. Suggestions are only the cached ones; the app
     * asks for missing ones one at a time, and only when "ai" is true.
     */
    public function queue(): JsonResponse
    {
        return response()->json([
            'ai' => ModerationAssist::enabled(),
            'items' => Moderate::queue()->map(fn (Dispatch $d) => [
                'id' => $d->id,
                'suggestion' => ModerationAssist::suggestion($d, compute: false),
                'past' => ModerationInsights::pastDecisions($d),
                'blur' => ModerationInsights::blur($d),
                'held_because' => ModerationInsights::heldBecause($d),
            ])->values(),
        ]);
    }

    public function suggest(Dispatch $dispatch): JsonResponse
    {
        abort_unless(ModerationAssist::enabled(), 503, 'Needs ANTHROPIC_API_KEY');

        return response()->json(['suggestion' => ModerationAssist::suggestion($dispatch->load('user'))]);
    }

    public function appeal(Appeal $appeal): JsonResponse
    {
        return response()->json(['ai' => ModerationAssist::enabled(), 'notes' => ModerationAssist::appeal($appeal)]);
    }

    public function brief(User $user): JsonResponse
    {
        return response()->json(ModerationAssist::brief($user));
    }

    public function insights(): JsonResponse
    {
        return response()->json(['days' => 30, 'categories' => ModerationInsights::thresholds()]);
    }

    // Watch phrases...

    public function watchPhrases(): JsonResponse
    {
        return response()->json(DB::table('watch_phrases')->orderBy('phrase')->get()
            ->map(fn ($p) => ['id' => $p->id, 'phrase' => $p->phrase, 'meaning' => $p->meaning, 'severity' => $p->severity, 'active' => (bool) $p->active]));
    }

    public function savePhrase(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'phrase' => ['required', 'string', 'min:3', 'max:120', Rule::unique('watch_phrases')->ignore($id)],
            'meaning' => ['required', 'string', 'max:500'],
            'severity' => ['required', Rule::in(WatchPhrases::SEVERITIES)],
            'active' => ['boolean'],
        ]);
        $data = ['phrase' => trim($data['phrase']), 'active' => $data['active'] ?? true, 'updated_at' => now()] + $data;

        if ($id) {
            abort_unless(DB::table('watch_phrases')->where('id', $id)->update($data), 404);
        } else {
            $id = DB::table('watch_phrases')->insertGetId($data + ['created_at' => now()]);
        }
        Audit::log('watch_phrase.saved', null, ['id' => $id, 'phrase' => $data['phrase'], 'active' => $data['active']]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function deletePhrase(int $id): JsonResponse
    {
        $phrase = DB::table('watch_phrases')->where('id', $id)->value('phrase');
        abort_unless($phrase !== null, 404);
        DB::table('watch_phrases')->delete($id);
        Audit::log('watch_phrase.deleted', null, ['id' => $id, 'phrase' => $phrase]);

        return response()->json(['ok' => true]);
    }
}
