<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Notifications')]
class Notifications extends Component
{
    use HandlesDispatchActions;

    public const FILTERS = ['all' => 'All', 'reply' => 'Replies', 'quote' => 'Quotes', 'mention' => 'Mentions', 'best' => 'Best answers', 'follow' => 'Follows', 'moderation' => 'Moderation'];

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:App.Models.User.'.Auth::id().',.notified' => '$refresh'];
    }

    #[Url(except: 'all')]
    public string $filter = 'all';

    public function render(): View
    {
        $user = Auth::user();
        $hidden = $user->hiddenUserIds();

        $notifications = $user->notifications()->latest()->take(200)->get()
            ->reject(fn ($n) => in_array($n->data['actor_id'] ?? 0, $hidden, true))
            ->when($this->filter !== 'all', fn ($c) => $c->filter(fn ($n) => ($n->data['kind'] ?? '') === $this->filter));

        // Group follows by day and everything else by thread, newest group first.
        $groups = $notifications
            ->groupBy(fn ($n) => ($n->data['kind'] ?? '').':'.(($n->data['kind'] ?? '') === 'follow' ? $n->created_at->toDateString() : ($n->data['thread_id'] ?? $n->id)))
            ->map(fn ($items) => [
                'kind' => $items->first()->data['kind'] ?? 'other',
                'latest' => $items->first(),
                'actors' => $items->pluck('data.actor')->unique()->values(),
                'unread' => $items->contains(fn ($n) => ! $n->read_at),
                'items' => $items,
            ])->take(50);

        $user->unreadNotifications()->update(['read_at' => now()]);

        $followerIds = $groups->where('kind', 'follow')->flatMap(fn ($g) => $g['items']->pluck('data.actor_id'));
        $followingBack = $user->following()->whereIn('users.id', $followerIds)->pluck('users.id')->all();

        return view('livewire.pages.notifications', [
            'groups' => $groups,
            'followingBack' => $followingBack,
            'actorsById' => User::whereIn('id', $followerIds)->get()->keyBy('id'),
        ]);
    }
}
