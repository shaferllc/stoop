<?php

namespace App\Http\Controllers\Api\App;

use App\Actions\DispatchActions;
use App\Actions\FileDispatch;
use App\Exceptions\NudgeRequired;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Place;
use App\Support\Achievements;
use App\Support\Settings;
use App\Support\Stations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feeds, threads, posting and card actions. Posting goes through FileDispatch and the card
 * actions through DispatchActions, the same code the website uses.
 */
class DispatchController extends Controller
{
    protected const PER_PAGE = 20;

    /**
     * Lines, stations, the types this person can post, and report reasons, so the app
     * never hardcodes them.
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user();
        $types = collect(config('stoop.types'))
            ->reject(fn ($label, $key) => ($key === 'callout' && ! $user->is_staff) || ($key === 'snow' && ! Settings::snowDay()));

        return response()->json(['data' => [
            'lines' => collect(config('stoop.lines'))->map(fn ($line, $key) => ['key' => $key, 'name' => $line['name'], 'color' => $line['color'], 'stops' => config("metro.lines.$key")])->values(),
            'stations' => collect(config('stoop.stations'))->keys()->map(fn ($code) => Present::stop($code))->sortBy('name')->values(),
            'types' => $types->map(fn ($label, $key) => ['key' => $key, 'name' => $label])->values(),
            'report_reasons' => collect(config('stoop.report_reasons'))->map(fn ($label, $key) => ['key' => $key, 'name' => $label])->values(),
            'max_length' => config('stoop.max_length'),
            'poll_hours' => [1, 24, 72, 168],
        ]]);
    }

    /**
     * Following, Rush Hour, Latest or Near me, optionally narrowed to a line or stop.
     */
    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['rush', 'latest', 'following', 'near'])],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $tab = $data['tab'] ?? 'rush';
        $page = (int) ($data['page'] ?? 1);

        $query = Dispatch::topLevel()->visibleTo($user)->forCards()
            ->when($data['line'] ?? null, fn ($q, $line) => $q->where('line', $line))
            ->when($data['stop'] ?? null, fn ($q, $stop) => $q->where('stop', $stop))
            ->when($tab === 'following', fn ($q) => $q->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhereIn('user_id', $user->following()->select('users.id'))))
            ->when($tab === 'near', fn ($q) => $q->whereIn('stop', $user->stops ?: ['']));

        $dispatches = $tab === 'rush'
            // Same ranking as the website's Rush Hour: the last week, scored in PHP.
            ? $query->where('created_at', '>=', now()->subWeek())->get()
                ->sortByDesc(fn (Dispatch $d) => $d->rushScore())
                ->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values()
            : $query->where('dispatches.created_at', '<=', now())->latest()->latest('id')->forPage($page, self::PER_PAGE)->get();

        return response()->json([
            'data' => Present::dispatches($dispatches, $user),
            'next_page' => $dispatches->count() === self::PER_PAGE ? $page + 1 : null,
        ]);
    }

    public function saved(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = max(1, (int) $request->query('page', 1));
        $dispatches = Dispatch::visibleTo($user)->forCards()
            ->whereIn('id', $user->marked()->wherePivot('kind', 'save')->select('dispatches.id'))
            ->latest()->forPage($page, self::PER_PAGE)->get();

        return response()->json([
            'data' => Present::dispatches($dispatches, $user),
            'next_page' => $dispatches->count() === self::PER_PAGE ? $page + 1 : null,
        ]);
    }

    /**
     * A dispatch and its replies, Best Answer first.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $found = Dispatch::withTrashed()->findOrFail($id);

        abort_unless(
            Dispatch::visibleTo($user)->whereKey($found->id)->exists() || ($found->trashed() && $found->replies()->exists()),
            404,
        );

        $dispatch = Dispatch::withTrashed()->forCards()->find($found->id);
        $replies = $dispatch->replies()->visibleTo($user)->forCards()->oldest()->get()
            ->sortBy(fn ($r) => $r->id === $dispatch->best_answer_id ? 0 : 1)->values();

        return response()->json([
            'data' => Present::dispatches(collect([$dispatch]), $user)[0],
            'replies' => Present::dispatches($replies, $user),
        ]);
    }

    /**
     * Post a dispatch or a reply. Screening, the nudge and moderation run exactly as on the
     * website: on a nudge, the app asks and sends again with "confirm": true.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $badgesBefore = Achievements::unlocked($user);

        try {
            $dispatch = FileDispatch::handle($user, [
                'type' => (string) $request->input('type', 'dispatch'),
                'body' => (string) $request->input('body', ''),
                'line' => (string) $request->input('line', 'red'),
                'stop' => $request->filled('stop') ? strtoupper((string) $request->input('stop')) : null,
                'happeningAt' => (string) $request->input('happening_at', ''),
                'options' => (array) $request->input('options', []),
                'pollHours' => (int) $request->input('poll_hours', 24),
                'publishAt' => (string) $request->input('publish_at', ''),
                'safetyWhen' => (string) $request->input('safety_when', ''),
                'safetyBlock' => (string) $request->input('safety_block', ''),
                'safetyPerson' => (string) $request->input('safety_person', ''),
                'parentId' => $request->integer('parent_id') ?: null,
                'quoteId' => $request->integer('quote_id') ?: null,
                'placeId' => $request->integer('place_id') ?: null,
                'happeningStarts' => (string) $request->input('happening_starts', ''),
                'lostKind' => $request->input('lost_kind'),
                'lostStatus' => $request->input('lost_status'),
            ], confirmed: $request->boolean('confirm'));
        } catch (NudgeRequired) {
            $message = 'That reads as a personal attack. Would you say it to a neighbor?';

            return response()->json(['message' => $message, 'nudge' => true, 'errors' => ['body' => [$message]]], 422);
        }

        $unlocked = array_diff_key(Achievements::unlocked($user->fresh()), $badgesBefore);

        return response()->json([
            'data' => Present::dispatches(collect([Dispatch::forCards()->find($dispatch->id)]), $user)[0],
            'notice' => match ($dispatch->status) {
                'held' => 'Held for review. A moderator will check it before it goes up.',
                'pending' => 'Filed. It goes up once it passes a quick check.',
                'scheduled' => 'Scheduled for '.$dispatch->publish_at->format('M j, g:ia').'.',
                default => $dispatch->parent_id ? 'Reply posted.' : 'Dispatch filed.',
            },
            'unlocked' => array_values($unlocked),
        ], 201);
    }

    public function mark(Request $request, int $id, string $kind): JsonResponse
    {
        $on = DispatchActions::toggleMark($request->user(), $this->visible($request, $id), $kind);

        return response()->json(['kind' => $kind, 'on' => $on]);
    }

    public function halfSmoke(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $dispatch = $this->visible($request, $id);

        if ($reason = DispatchActions::giveHalfSmoke($user, $dispatch)) {
            return response()->json(['message' => $reason], 422);
        }

        return response()->json(['count' => $dispatch->halfSmokes()->count(), 'left' => $user->halfSmokesLeft()]);
    }

    public function vote(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['option_id' => ['required', 'integer']]);
        $user = $request->user();
        DispatchActions::vote($user, $this->visible($request, $id), $data['option_id']);

        return response()->json(['data' => Present::dispatches(Dispatch::forCards()->whereKey($id)->get(), $user)[0]]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(config('stoop.report_reasons')))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DispatchActions::report($request->user(), $this->visible($request, $id), $data['reason'], $data['note'] ?? null);

        return response()->json(['message' => 'Reported and hidden for you. A moderator will review it.']);
    }

    /**
     * Places to tag in a Been here dispatch, best-ranked first.
     */
    public function places(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $places = Place::query()
            ->when(mb_strlen($q) >= 2, fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->orderByRaw('list_rank is null, list_rank')->orderBy('name')->take(30)->get();

        return response()->json(['data' => $places->map(fn (Place $p) => [
            'id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'neighborhood' => $p->neighborhood,
            'stop' => $p->stop ? Present::stop($p->stop) : null, 'list' => $p->listLabel(),
        ])]);
    }

    protected function visible(Request $request, int $id): Dispatch
    {
        return Dispatch::visibleTo($request->user())->findOrFail($id);
    }
}
