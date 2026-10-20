<?php

namespace App\Livewire\Pages;

use App\Models\Dispatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Moderation')]
class Moderation extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
    }

    /**
     * Put a dispatch back up and close its reports.
     */
    public function approve(int $id): void
    {
        abort_unless(Auth::user()->is_staff, 403);

        $dispatch = Dispatch::findOrFail($id);
        $dispatch->update(['status' => 'approved']);
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Flux::toast('Approved.');
    }

    public function remove(int $id): void
    {
        abort_unless(Auth::user()->is_staff, 403);

        $dispatch = Dispatch::findOrFail($id);
        $dispatch->update(['status' => 'removed']);
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Flux::toast('Removed.');
    }

    /**
     * Penalty ladder: a 24-hour mute, then 7 days. Bans are a separate, manual step.
     */
    public function muteAuthor(int $id, int $hours): void
    {
        abort_unless(Auth::user()->is_staff && in_array($hours, [24, 168], true), 403);

        $user = User::findOrFail(Dispatch::findOrFail($id)->user_id);
        $user->forceFill(['muted_until' => now()->addHours($hours)])->save();
        Flux::toast("Muted @{$user->username} for ".($hours === 24 ? '24 hours.' : '7 days.'));
    }

    public function render(): View
    {
        $queue = Dispatch::with(['user', 'reports.user'])
            ->where(fn ($q) => $q->whereIn('status', ['held', 'hidden'])
                ->orWhereHas('reports', fn ($q) => $q->whereNull('resolved_at')))
            ->whereNotIn('status', ['removed'])
            ->latest()->take(50)->get();

        return view('livewire.pages.moderation', ['queue' => $queue]);
    }
}
