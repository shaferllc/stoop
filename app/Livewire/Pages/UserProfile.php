<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use App\Models\User;
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

    public function mount(User $user): void
    {
        abort_if(Auth::check() && in_array($user->id, Auth::user()->hiddenUserIds(), true) && Auth::id() !== $user->id, 404);

        $this->userId = $user->id;
    }

    public function render(): View
    {
        $user = User::withCount(['followers', 'following'])->findOrFail($this->userId);
        $viewer = Auth::user();

        $dispatches = Dispatch::visibleTo($viewer)->forCards()->with('parent.user')
            ->where('user_id', $user->id)
            ->when($this->show === 'replies', fn ($q) => $q->whereNotNull('parent_id'), fn ($q) => $q->whereNull('parent_id'))
            ->latest()->take(50)->get();

        return view('livewire.pages.user-profile', [
            'user' => $user,
            'dispatches' => $dispatches,
            'isFollowing' => $viewer?->following()->whereKey($user->id)->exists() ?? false,
            'followsYou' => $viewer ? $user->following()->whereKey($viewer->id)->exists() : false,
            'state' => $this->viewerState($dispatches),
        ])->title($user->name.' (@'.$user->username.')');
    }
}
