<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Achievements;
use App\Support\RidershipGraph;
use App\Support\Stations;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
class UserProfile extends Component
{
    use HandlesDispatchActions;

    #[Locked]
    public int $userId;

    #[Url]
    public string $show = 'dispatches';

    public string $liveUrl = '';

    public string $liveLabel = '';

    public int $liveHours = 2;

    public function mount(User $user): void
    {
        abort_if(Auth::check() && in_array($user->id, Auth::user()->hiddenUserIds(), true) && Auth::id() !== $user->id, 404);

        $this->userId = $user->id;
    }

    /**
     * Mark yourself "On the scene" with a stream link for 1 to 12 hours.
     */
    public function goLive(): void
    {
        abort_unless(Auth::id() === $this->userId, 403);

        $this->validate([
            'liveUrl' => ['required', 'url:https', 'max:300', function ($attribute, $value, $fail) {
                if (! in_array(strtolower((string) parse_url($value, PHP_URL_HOST)), config('stoop.live_hosts'), true)) {
                    $fail('Use a YouTube, Twitch or Instagram link.');
                }
            }],
            'liveLabel' => ['nullable', 'string', 'max:80'],
            'liveHours' => ['required', 'integer', 'between:1,12'],
        ]);

        Auth::user()->forceFill([
            'live_url' => $this->liveUrl,
            'live_label' => $this->liveLabel ?: null,
            'live_until' => now()->addHours($this->liveHours),
        ])->save();

        $this->reset('liveUrl', 'liveLabel');
        Flux::toast('You’re on the scene.');
    }

    public function endLive(): void
    {
        abort_unless(Auth::id() === $this->userId, 403);
        Auth::user()->forceFill(['live_url' => null, 'live_label' => null, 'live_until' => null])->save();
    }

    public function render(): View
    {
        $user = User::withCount(['followers', 'following'])->findOrFail($this->userId);
        $viewer = Auth::user();

        $dispatches = Dispatch::visibleTo($viewer)->forCards()->with('parent.user')
            ->where('user_id', $user->id)
            ->when($this->show === 'replies', fn ($q) => $q->whereNotNull('parent_id'), fn ($q) => $q->whereNull('parent_id'))
            ->latest()->take(50)->get();

        $pinned = $this->show === 'dispatches' && $user->pinned_dispatch_id
            ? $dispatches->firstWhere('id', $user->pinned_dispatch_id) ?? Dispatch::visibleTo($viewer)->forCards()->find($user->pinned_dispatch_id)
            : null;
        if ($pinned) {
            $dispatches = $dispatches->reject(fn ($d) => $d->id === $pinned->id)->prepend($pinned);
        }

        return view('livewire.pages.user-profile', [
            'user' => $user,
            'pinnedId' => $pinned?->id,
            'xp' => $xp = Achievements::xp($user),
            'rank' => Achievements::rank($xp),
            'badges' => Achievements::unlocked($user),
            'streaks' => Achievements::streaks($user),
            'graph' => RidershipGraph::svg($user, 'light'),
            'visited' => Stations::visited($user),
            'mayorships' => Stations::mayorships($user),
            'dispatches' => $dispatches,
            'isFollowing' => $viewer?->following()->whereKey($user->id)->exists() ?? false,
            'followsYou' => $viewer ? $user->following()->whereKey($viewer->id)->exists() : false,
            'state' => $this->viewerState($dispatches),
        ])->title($user->name.' (@'.$user->username.')')->layoutData([
            'description' => $user->name.' on Stoop · '.$user->badge(),
            'ogImage' => route('users.og', $user),
        ]);
    }
}
