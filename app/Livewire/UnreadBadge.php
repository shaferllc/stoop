<?php

namespace App\Livewire;

use App\Support\Messaging;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The unread count next to Notifications or Messages. Updates the moment something
 * arrives when Reverb is running, and every minute otherwise.
 */
class UnreadBadge extends Component
{
    #[Locked]
    public string $type = 'notifications';

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:App.Models.User.'.Auth::id().',.notified' => '$refresh'];
    }

    public function render(): View
    {
        $user = Auth::user();
        $count = $this->type === 'messages' ? Messaging::unreadCount($user) : $user->unreadNotifications()->count();

        return view('livewire.unread-badge', ['count' => $count]);
    }
}
