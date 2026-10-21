<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use App\Support\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Messages. Who can write to whom, requests, timeouts and bans all live in
 * App\Support\Messaging, shared with the website.
 */
class MessagesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $box = in_array($request->query('box'), ['requests', 'archived'], true) ? $request->query('box') : 'inbox';

        $conversations = Conversation::with('participants')
            ->whereHas('participants', fn ($q) => $q->whereKey($user->id)->where(fn ($q) => match ($box) {
                'requests' => $q->whereNull('conversation_participants.accepted_at'),
                'archived' => $q->whereNotNull('conversation_participants.accepted_at')->whereNotNull('conversation_participants.archived_at'),
                default => $q->whereNotNull('conversation_participants.accepted_at')->whereNull('conversation_participants.archived_at'),
            }))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->take(100)->get();

        $latest = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');

        return response()->json(['data' => $conversations->map(fn (Conversation $c) => [
            ...$this->summary($c, $user),
            'latest' => ($m = $latest[$c->id] ?? null) ? ['body' => $m->body, 'mine' => $m->user_id === $user->id, 'created_at' => $m->created_at->toIso8601String()] : null,
            'unread' => $c->last_message_at && (! ($read = $c->pivotFor($user)->last_read_at) || $c->last_message_at->gt($read)),
        ])]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);
        $usernames = array_values(array_filter(preg_split('/[\s,@]+/', $data['to'])));
        $people = User::whereIn('username', $usernames)->get();

        if ($people->count() !== count(array_unique(array_map('strtolower', $usernames)))) {
            throw ValidationException::withMessages(['to' => 'We couldn’t find everyone. Check the usernames.']);
        }

        $conversation = Messaging::start($request->user(), $people->all(), ($data['name'] ?? null) ?: null);

        return response()->json(['data' => $this->summary($conversation->load('participants'), $request->user())], 201);
    }

    /**
     * The latest 100 messages. Opening an accepted conversation marks it read.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->conversation($request, $id);
        $pivot = $conversation->pivotFor($user);

        if ($pivot->accepted_at) {
            DB::table('conversation_participants')->where(['conversation_id' => $conversation->id, 'user_id' => $user->id])->update(['last_read_at' => now()]);
        }

        $messages = $conversation->messages()->with(['user', 'images', 'reactions'])->latest('id')->take(100)->get()->reverse()->values();
        $others = $conversation->participants->where('id', '!=', $user->id);

        // Receipts show only when both people have them on.
        $seenAt = ! $conversation->is_group && $user->read_receipts && ($other = $others->first())?->read_receipts
            ? $other->pivot->last_read_at
            : null;

        return response()->json([
            'data' => [...$this->summary($conversation, $user), 'seen_at' => $seenAt ? Carbon::parse($seenAt)->toIso8601String() : null],
            'messages' => $messages->map(fn (Message $m) => $this->message($m, $user)),
        ]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
            'reply_to' => ['nullable', 'integer'],
        ]);

        $message = Messaging::send($request->user(), $this->conversation($request, $id), $data['body'], [], $data['reply_to'] ?? null);

        return response()->json(['data' => $this->message($message->load(['user', 'images', 'reactions']), $request->user())], 201);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $this->conversation($request, $id);
        DB::table('conversation_participants')->where(['conversation_id' => $id, 'user_id' => $request->user()->id])
            ->update(['accepted_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Decline a request. The sender isn't told.
     */
    public function decline(Request $request, int $id): JsonResponse
    {
        $this->conversation($request, $id)->participants()->detach($request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(config('stoop.report_reasons')))]]);
        $message = Message::where('user_id', '!=', $request->user()->id)->findOrFail($id);
        $this->conversation($request, $message->conversation_id);

        MessageReport::firstOrCreate(['message_id' => $message->id, 'user_id' => $request->user()->id], ['reason' => $data['reason']]);

        return response()->json(['message' => 'Reported. A moderator will read this message and the ones around it.']);
    }

    protected function conversation(Request $request, int $id): Conversation
    {
        $conversation = Conversation::with('participants')->findOrFail($id);
        abort_unless($conversation->pivotFor($request->user()), 404);

        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Conversation $c, User $viewer): array
    {
        $pivot = $c->pivotFor($viewer);

        return [
            'id' => $c->id,
            'title' => $c->titleFor($viewer),
            'is_group' => (bool) $c->is_group,
            'accepted' => (bool) $pivot?->accepted_at,
            'archived' => (bool) $pivot?->archived_at,
            'people' => $c->participants->where('id', '!=', $viewer->id)->map(fn ($p) => Present::user($p))->values(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(Message $m, User $viewer): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'author' => Present::user($m->user),
            'mine' => $m->user_id === $viewer->id,
            'reply_to_id' => $m->reply_to_id,
            'photos' => $m->images->count(),
            'reactions' => $m->reactions->groupBy('emoji')->map(fn ($r, $emoji) => ['emoji' => $emoji, 'count' => $r->count(), 'mine' => $r->contains('user_id', $viewer->id)])->values(),
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
