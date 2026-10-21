<?php

namespace App\Http\Controllers\Api;

use App\Livewire\Admin\Tips as TipsPage;
use App\Models\Dispatch;
use App\Models\Tip;
use App\Support\Audit;
use App\Support\Callouts;
use App\Support\Radar;
use App\Support\Tips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Newsroom endpoints for the Stoop Admin desktop app: story radar, the tip inbox and
 * callout quotes. Same staff-and-admin-token check as AdminController (inherited).
 */
class EditorialController extends AdminController
{
    public function radar(Request $request): JsonResponse
    {
        if ($request->boolean('fresh')) {
            Cache::forget('radar');
        }

        return response()->json(Radar::get() + ['ideas_enabled' => Radar::ideasEnabled()]);
    }

    public function idea(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:200']]);
        if (! Radar::ideasEnabled()) {
            return response()->json(['message' => 'Needs ANTHROPIC_API_KEY.'], 422);
        }

        $trend = collect(Radar::get())->except('generated_at')->flatten(1)->firstWhere('key', $data['key']);
        abort_unless($trend, 404, 'That trend isn’t on the radar any more.');

        return response()->json(['idea' => Radar::idea($trend)]);
    }

    public function tips(Request $request): JsonResponse
    {
        $status = $request->query('status', 'new');

        return response()->json(Tip::with(['user', 'assignee', 'dispatch'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()->take(100)->get()
            ->map(fn (Tip $t) => [
                'id' => $t->id,
                'body' => $t->body,
                'link' => $t->link,
                'status' => $t->status,
                'contact_ok' => $t->contact_ok,
                'photos' => collect($t->photos ?? [])->keys()->map(fn ($n) => route('admin.tips.photo', [$t, $n])),
                'flagged' => array_keys($t->screening['flagged'] ?? []),
                'created_at' => $t->created_at,
                'user' => $this->person($t->user),
                'assignee' => $t->assignee ? $this->person($t->assignee) : null,
                'dispatch' => $t->dispatch ? ['id' => $t->dispatch->id, 'url' => route('dispatches.show', $t->dispatch_id), 'body' => $t->dispatch->body] : null,
            ]));
    }

    public function editors(): JsonResponse
    {
        return response()->json(TipsPage::editors()->map(fn ($u) => ['id' => $u->id, 'username' => $u->username]));
    }

    public function updateTip(Request $request, Tip $tip): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(Tip::STATUSES))],
            'assigned_to' => ['sometimes', 'nullable', Rule::in(TipsPage::editors()->pluck('id'))],
        ]);
        $tip->update($data);
        Audit::log('tip.updated', $tip, $data);

        return response()->json(['ok' => true]);
    }

    public function replyToTip(Request $request, Tip $tip): JsonResponse
    {
        Tips::reply($tip->load('user'), $request->user(), (string) $request->input('body'));
        if ($tip->status === 'new') {
            $tip->update(['status' => 'following']);
        }

        return response()->json(['ok' => true]);
    }

    public function callouts(): JsonResponse
    {
        return response()->json(Dispatch::where('type', 'callout')->whereNull('parent_id')->where('status', 'approved')
            ->withCount(['replies as quotable_count' => fn ($q) => $q->where('status', 'approved')->where('details->quote_ok', true)])
            ->latest()->take(30)->get()
            ->map(fn (Dispatch $d) => ['id' => $d->id, 'body' => $d->body, 'url' => route('dispatches.show', $d), 'story' => $d->details['story'] ?? null, 'quotable' => $d->quotable_count, 'created_at' => $d->created_at]));
    }

    public function quotes(Dispatch $dispatch): JsonResponse
    {
        abort_unless($dispatch->type === 'callout' && ! $dispatch->parent_id, 404);

        return response()->json(Callouts::quotes($dispatch)->map(fn (Dispatch $r) => [
            'id' => $r->id, 'quote' => $r->body, 'user' => $this->person($r->user), 'url' => route('dispatches.show', $r), 'created_at' => $r->created_at,
        ]));
    }
}
