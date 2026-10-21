<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Support\Happenings;
use App\Support\LostAndFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Happenings calendar, host tools and calendar feed, and the Lost & found board.
 * RSVPs go through the usual marks endpoint (kinds "going" and "interested").
 */
class HappeningsController extends Controller
{
    /**
     * Happenings for a week or a month (DC time), with RSVP and ticket details.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(['week', 'month'])],
            'offset' => ['nullable', 'integer', 'between:-12,12'],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
        ]);
        [$from, $to] = Happenings::range($data['view'] ?? 'week', (int) ($data['offset'] ?? 0));
        $user = $request->user();

        $dispatches = Happenings::between($from->utc(), $to->utc(), $user, $data['line'] ?? null, $data['stop'] ?? null)->forCards()->get();
        $cards = Present::dispatches($dispatches, $user);

        return response()->json([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'data' => $dispatches->values()->map(fn (Dispatch $d, $i) => [...$cards[$i], 'happening' => Happenings::present($d)]),
        ]);
    }

    /**
     * Who's going and interested. Only the host (and staff) see names.
     */
    public function rsvps(Request $request, int $id): JsonResponse
    {
        $dispatch = $this->happening($request, $id);
        abort_unless($request->user()->id === $dispatch->user_id || $request->user()->is_staff, 403);

        $rsvps = Happenings::rsvps($dispatch);

        return response()->json(['data' => [
            'going' => $rsvps['going']->map(fn ($u) => Present::user($u))->values(),
            'interested' => $rsvps['interested']->map(fn ($u) => Present::user($u))->values(),
            'ticket_clicks' => (int) $dispatch->happening?->ticket_clicks,
            'happening' => Happenings::present($dispatch),
        ]]);
    }

    /**
     * Host: start time (DC time, "Y-m-d H:i"), and for owners and staff a price and ticket link.
     * Staff: "featured".
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $dispatch = $this->happening($request, $id);

        if ($request->hasAny(['starts_at', 'price', 'ticket_url'])) {
            Happenings::saveDetails($user, $dispatch, $request->only(['starts_at', 'price', 'ticket_url']));
        }
        if ($request->has('featured')) {
            Happenings::feature($user, $dispatch, $request->boolean('featured'));
        }

        return response()->json(['data' => Happenings::present($dispatch->fresh())]);
    }

    /**
     * The personal calendar feed link (made on first use); "reset" makes a new one.
     */
    public function feed(Request $request): JsonResponse
    {
        return response()->json(['url' => route('calendar.feed', Happenings::feedToken($request->user(), $request->boolean('reset')))]);
    }

    /**
     * The Lost & found board.
     */
    public function lostFound(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'kind' => ['nullable', Rule::in(array_keys(LostAndFound::KINDS))],
            'reunited' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $page = (int) ($data['page'] ?? 1);

        $dispatches = LostAndFound::board($data['stop'] ?? null, $data['line'] ?? null, $data['kind'] ?? null, $request->boolean('reunited'), $user)
            ->forCards()->latest()->forPage($page, 20)->get();
        $cards = Present::dispatches($dispatches, $user);

        return response()->json([
            'data' => $dispatches->values()->map(fn (Dispatch $d, $i) => [...$cards[$i], 'lost' => [
                'kind' => $d->details['lost_kind'] ?? null,
                'status' => $d->details['lost_status'] ?? null,
                'reunited' => (bool) ($d->details['reunited_at'] ?? null),
                'label' => LostAndFound::label($d->details ?? []),
            ]]),
            'next_page' => $dispatches->count() === 20 ? $page + 1 : null,
        ]);
    }

    public function reunited(Request $request, int $id): JsonResponse
    {
        LostAndFound::reunite($request->user(), Dispatch::visibleTo($request->user())->findOrFail($id));

        return response()->json(['message' => 'Marked as reunited.']);
    }

    protected function happening(Request $request, int $id): Dispatch
    {
        return Dispatch::visibleTo($request->user())->where('type', 'happening')->whereNull('parent_id')->findOrFail($id);
    }
}
