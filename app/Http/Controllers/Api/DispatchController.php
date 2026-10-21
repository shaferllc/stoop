<?php

namespace App\Http\Controllers\Api;

use App\Actions\FileDispatch;
use App\Exceptions\NudgeRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\DispatchResource;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Magazine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public read API, plus posting with a personal token. Posting runs through the same
 * FileDispatch action as the website, so the rules and limits are identical.
 */
class DispatchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $dispatches = Dispatch::topLevel()->visibleTo(null)->forCards()
            ->when($request->query('line'), fn ($q, $line) => $q->where('line', $line))
            ->when($request->query('stop'), fn ($q, $stop) => $q->where('stop', strtoupper($stop)))
            ->when($request->query('user'), fn ($q, $u) => $q->whereRelation('user', 'username', $u))
            ->latest()->latest('id')->paginate(50);

        return DispatchResource::collection($dispatches);
    }

    public function show(int $id): JsonResponse
    {
        $dispatch = Dispatch::visibleTo(null)->forCards()->findOrFail($id);
        $replies = $dispatch->replies()->visibleTo(null)->forCards()->oldest()->get();

        return response()->json([
            'data' => new DispatchResource($dispatch),
            'replies' => DispatchResource::collection($replies),
        ]);
    }

    public function user(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'username' => $user->username,
            'name' => $user->name,
            'badge' => $user->badge(),
            'followers' => $user->followers()->count(),
            'following' => $user->following()->count(),
            'url' => route('users.show', $user),
        ]]);
    }

    /**
     * How many dispatches link to an article, for a "12 neighbors are talking about this" line
     * on the magazine's article pages.
     */
    public function discussions(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'url', 'max:2048']]);
        $url = $request->query('url');

        // Replies under posts that share the article count as part of the conversation.
        $latest = Magazine::discussion($url)->with('user')->latest()->take(3)->get()
            ->map(fn ($d) => ['body' => $d->body, 'author' => $d->user?->username, 'url' => route('dispatches.show', $d), 'at' => $d->created_at->toIso8601String()]);

        return response()->json([
            'url' => $url,
            'count' => Magazine::discussion($url)->count(),
            'latest' => $latest,
            'join' => route('home', ['share' => $url]),
        ])->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=60');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('dispatch:write'), 403, 'This token can’t post.');

        try {
            $dispatch = FileDispatch::handle($request->user(), [
                'type' => $request->input('type', 'dispatch'),
                'body' => (string) $request->input('body', ''),
                'line' => $request->input('line', 'red'),
                'stop' => $request->input('stop') ? strtoupper($request->input('stop')) : null,
                'happeningAt' => (string) $request->input('happening_at', ''),
                'options' => (array) $request->input('options', []),
                'pollHours' => (int) $request->input('poll_hours', 24),
                'publishAt' => (string) $request->input('publish_at', ''),
                'parentId' => $request->input('parent_id'),
                'quoteId' => $request->input('quote_id'),
            ], confirmed: $request->boolean('confirm'));
        } catch (NudgeRequired) {
            return response()->json([
                'message' => 'That reads as a personal attack. Would you say it to a neighbor? Send it again with "confirm": true to post anyway.',
            ], 422);
        }

        return (new DispatchResource($dispatch->load('user')))->response()->setStatusCode(201);
    }
}
