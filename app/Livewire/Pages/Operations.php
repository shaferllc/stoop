<?php

namespace App\Livewire\Pages;

use App\Models\Dispatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Operations')]
class Operations extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
    }

    public function retry(string $uuid): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Flux::toast('Queued again.');
    }

    public function forget(string $uuid): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    public function render(): View
    {
        $day = now()->subDay();

        return view('livewire.pages.operations', [
            'stats' => [
                'Accounts' => User::where('is_bot', false)->count(),
                'New accounts, last 24 hours' => User::where('created_at', '>=', $day)->count(),
                'People who posted, last 24 hours' => Dispatch::where('created_at', '>=', $day)->distinct()->count('user_id'),
                'Dispatches, last 24 hours' => Dispatch::where('created_at', '>=', $day)->count(),
                'Waiting for moderators' => Dispatch::whereIn('status', ['held', 'hidden'])->orWhereNotNull('flagged_at')->count(),
                'Scheduled' => Dispatch::where('status', 'scheduled')->count(),
                'Jobs waiting' => DB::table('jobs')->count(),
            ],
            'failed' => DB::table('failed_jobs')->latest('failed_at')->take(25)->get(),
        ]);
    }
}
