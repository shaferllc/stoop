<?php

namespace App\Http\Controllers\Api\App;

use App\Actions\FileDispatch;
use App\Exceptions\NudgeRequired;
use App\Http\Controllers\Controller;
use App\Models\Ballot;
use App\Models\Dispatch;
use App\Models\EditorThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Ask the editor" threads and Best of Washington ballots.
 */
class MagazineController extends Controller
{
    public function thread(Request $request, int $id): JsonResponse
    {
        $thread = EditorThread::with('editor')->findOrFail($id);

        return response()->json(['data' => [
            'id' => $thread->id,
            'title' => $thread->title,
            'dispatch_id' => $thread->dispatch_id,
            'editor' => Present::user($thread->editor),
            'starts_at' => $thread->starts_at->toIso8601String(),
            'ends_at' => $thread->ends_at->toIso8601String(),
            'open' => $thread->isOpen(),
            'questions' => $thread->questions($request->user())->map(fn (Dispatch $q) => [
                'id' => $q->id,
                'body' => $q->body,
                'status' => $q->status,
                'author' => Present::user($q->user),
                'votes' => (int) $q->votes,
                'voted' => $q->voted,
                'created_at' => $q->created_at->toIso8601String(),
                'answers' => $q->answers->map(fn (Dispatch $a) => ['id' => $a->id, 'body' => $a->body, 'created_at' => $a->created_at->toIso8601String()])->values(),
            ])->values(),
        ]]);
    }

    public function ask(Request $request, int $id): JsonResponse
    {
        $thread = EditorThread::findOrFail($id);
        abort_unless($thread->isOpen(), 422, 'Questions are closed.');

        try {
            $question = FileDispatch::handle($request->user(), ['body' => $request->input('body', ''), 'parentId' => $thread->dispatch_id], confirmed: $request->boolean('confirm'));
        } catch (NudgeRequired) {
            $message = 'That reads as a personal attack. Would you say it to a neighbor?';

            return response()->json(['message' => $message, 'nudge' => true, 'errors' => ['body' => [$message]]], 422);
        }

        return response()->json(['data' => ['id' => $question->id, 'status' => $question->status]], 201);
    }

    public function vote(Request $request, int $id, int $question): JsonResponse
    {
        return response()->json(['data' => ['voted' => EditorThread::findOrFail($id)->toggleVote($request->user(), $question)]]);
    }

    public function ballot(Request $request, int $id): JsonResponse
    {
        $ballot = Ballot::findOrFail($id);

        return response()->json(['data' => [
            'id' => $ballot->id,
            'title' => $ballot->title,
            'opens_at' => $ballot->opens_at->toIso8601String(),
            'closes_at' => $ballot->closes_at->toIso8601String(),
            'open' => $ballot->isOpen(),
            'closed' => $ballot->isClosed(),
            'categories' => $ballot->categories($request->user())->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'my_pick' => $c->pick,
                'winner' => $c->winner?->id,
                'nominees' => $c->nominees->map(fn ($n) => ['id' => $n->id, 'place_id' => $n->place_id, 'name' => $n->name, 'neighborhood' => $n->neighborhood, 'votes' => $n->votes])->values(),
            ])->values(),
        ]]);
    }

    public function ballotVote(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['nominee_id' => ['required', 'integer']]);
        $error = Ballot::findOrFail($id)->vote($request->user(), $data['nominee_id']);

        return $error ? response()->json(['message' => $error], 422) : response()->json(['data' => ['voted' => true]]);
    }
}
