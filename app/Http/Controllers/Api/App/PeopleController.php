<?php

namespace App\Http\Controllers\Api\App;

use App\Actions\DispatchActions;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Metro;
use App\Support\Rivalries;
use App\Support\SearchIndex;
use App\Support\Stations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Profiles, follows, blocks, notifications, search and station pages.
 */
class PeopleController extends Controller
{
    /**
     * A profile with the Metro strip map, badges and ridership, plus dispatches or replies.
     */
    public function profile(Request $request, string $username): JsonResponse
    {
        $viewer = $request->user();
        $user = $this->person($request, $username, allowSelf: true);
        $user->loadCount(['followers', 'following']);
        $show = $request->query('show') === 'replies' ? 'replies' : 'dispatches';

        $dispatches = Dispatch::visibleTo($viewer)->forCards()
            ->where('user_id', $user->id)
            ->when($show === 'replies', fn ($q) => $q->whereNotNull('parent_id'), fn ($q) => $q->whereNull('parent_id'))
            ->latest()->take(50)->get();

        $pinned = $show === 'dispatches' && $user->pinned_dispatch_id
            ? $dispatches->firstWhere('id', $user->pinned_dispatch_id) ?? Dispatch::visibleTo($viewer)->forCards()->find($user->pinned_dispatch_id)
            : null;
        if ($pinned) {
            $dispatches = $dispatches->reject(fn ($d) => $d->id === $pinned->id)->prepend($pinned);
        }

        $xp = Achievements::xp($user);

        return response()->json(['data' => [
            ...Present::user($user),
            'live' => $user->isLive() ? ['url' => $user->live_url, 'label' => $user->live_label, 'until' => $user->live_until->toIso8601String()] : null,
            'followers_count' => $user->followers_count,
            'following_count' => $user->following_count,
            'joined_at' => $user->created_at?->toIso8601String(),
            'is_me' => $user->id === $viewer->id,
            'is_following' => $viewer->following()->whereKey($user->id)->exists(),
            'follows_you' => $user->following()->whereKey($viewer->id)->exists(),
            'xp' => $xp,
            'rank' => Achievements::rank($xp),
            'badges' => array_values(Achievements::unlocked($user)),
            'streaks' => Achievements::streaks($user),
            'ridership' => (object) Achievements::dailyCounts($user, 91),
            'visited' => Stations::visited($user),
            'mayorships' => array_map(fn ($code) => Present::stop($code), Stations::mayorships($user)),
            'pinned_id' => $pinned?->id,
            'dispatches' => Present::dispatches($dispatches, $viewer),
        ]]);
    }

    public function follow(Request $request, string $username): JsonResponse
    {
        $following = DispatchActions::toggleFollow($request->user(), $this->person($request, $username));

        return response()->json(['following' => $following]);
    }

    public function silence(Request $request, string $username, string $type): JsonResponse
    {
        $target = $this->person($request, $username);
        DispatchActions::silence($request->user(), $target, $type);

        return response()->json(['message' => $type === 'block' ? "Blocked @{$target->username}." : "Muted @{$target->username}. Their posts won’t show for you."]);
    }

    /**
     * Your latest notifications, minus anyone you've muted or blocked. Opening them marks
     * them read, as on the website.
     */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $hidden = $user->hiddenUserIds();

        $notifications = $user->notifications()->latest()->take(200)->get()
            ->reject(fn ($n) => in_array($n->data['actor_id'] ?? 0, $hidden, true))
            ->take(100)->values();

        $actors = User::whereIn('id', $notifications->pluck('data.actor_id')->filter()->unique())->get()->keyBy('id');
        $followingBack = $user->following()->whereIn('users.id', $actors->keys())->pluck('users.id')->all();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => $notifications->map(fn ($n) => [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? 'other',
            'actor' => Present::user($actors[$n->data['actor_id'] ?? 0] ?? null),
            'following_actor' => in_array($n->data['actor_id'] ?? 0, $followingBack, true),
            'dispatch_id' => $n->data['dispatch_id'] ?? null,
            'excerpt' => $n->data['excerpt'] ?? null,
            'message' => $n->data['message'] ?? null,
            'read' => (bool) $n->read_at,
            'created_at' => $n->created_at->toIso8601String(),
        ])]);
    }

    /**
     * Dispatches (full-text with stemming, like the website), people, or stations.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'tab' => ['nullable', Rule::in(['dispatches', 'people', 'stations'])],
        ]);
        $q = trim($data['q']);
        $viewer = $request->user();

        return response()->json(['data' => match ($data['tab'] ?? 'dispatches') {
            'people' => User::where('is_bot', false)
                ->whereNotIn('id', $viewer->hiddenUserIds())
                ->where(fn ($w) => $w->where('username', 'like', ltrim($q, '@').'%')->orWhere('name', 'like', '%'.$q.'%'))
                ->withCount('followers')->orderByDesc('followers_count')->take(30)->get()
                ->map(fn ($u) => [...Present::user($u), 'followers_count' => $u->followers_count])->values(),
            'stations' => collect(config('stoop.stations'))
                ->filter(fn ($name, $code) => str_contains(strtolower($name.' '.$code), strtolower($q)))
                ->keys()->map(fn ($code) => Present::stop($code))->values(),
            default => Present::dispatches(SearchIndex::search(Dispatch::visibleTo($viewer)->forCards(), $q), $viewer),
        }]);
    }

    /**
     * A station page: lines, mayor, next trains and Bikeshare (when reachable), and the
     * latest dispatches tagged there.
     */
    public function station(Request $request, string $code): JsonResponse
    {
        $code = strtoupper($code);
        $code = config("metro.aliases.$code", $code);
        abort_unless(config("stoop.stations.$code"), 404);

        $viewer = $request->user();
        $mayor = Stations::mayorOf($code);
        $dispatches = Dispatch::topLevel()->visibleTo($viewer)->forCards()->where('stop', $code)->latest()->latest('id')->take(30)->get();

        return response()->json(['data' => [
            ...Present::stop($code),
            'jurisdiction' => Stations::jurisdiction($code),
            'east_of_the_river' => Stations::eastOfTheRiver($code),
            'mayor' => $mayor && ! in_array($mayor->id, $viewer->hiddenUserIds(), true) ? Present::user($mayor) : null,
            'crowned' => (bool) Rivalries::crownFor($code),
            'next_trains' => Metro::nextTrains($code),
            'bikeshare' => Metro::bikeshare($code),
            'dispatches' => Present::dispatches($dispatches, $viewer),
        ]]);
    }

    /**
     * Someone by username, as long as neither of you has hidden the other.
     */
    protected function person(Request $request, string $username, bool $allowSelf = false): User
    {
        $user = User::where('username', ltrim($username, '@'))->firstOrFail();
        $viewer = $request->user();

        abort_if($user->id !== $viewer->id && in_array($user->id, $viewer->hiddenUserIds(), true), 404);
        abort_if(! $allowSelf && $user->id === $viewer->id, 422, 'That’s you.');

        return $user;
    }
}
