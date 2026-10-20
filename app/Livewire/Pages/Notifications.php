<?php

namespace App\Livewire\Pages;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Notifications')]
class Notifications extends Component
{
    public function render(): View
    {
        $user = Auth::user();
        $notifications = $user->notifications()->take(50)->get()
            ->reject(fn ($n) => in_array($n->data['actor_id'] ?? 0, $user->hiddenUserIds(), true));

        $user->unreadNotifications()->update(['read_at' => now()]);

        return view('livewire.pages.notifications', ['notifications' => $notifications]);
    }
}
