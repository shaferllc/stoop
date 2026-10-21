<?php

namespace App\Livewire\Admin;

use App\Models\Dispatch;
use App\Models\User;
use App\Support\Attention;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Admin')]
class Dashboard extends Component
{
    use StaffOnly;

    public function retry(string $uuid): void
    {
        abort_unless($this->staff()->hasRole('admin'), 403);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Flux::toast('Queued again.');
    }

    public function forget(string $uuid): void
    {
        abort_unless($this->staff()->hasRole('admin'), 403);
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    public function render(): View
    {
        $day = now()->subDay();
        $week = now()->subWeek();

        return view('livewire.admin.dashboard', [
            'stats' => [
                'Accounts' => User::where('is_bot', false)->count(),
                'New accounts, last 7 days' => User::where('is_bot', false)->where('created_at', '>=', $week)->count(),
                'People who posted, last 24 hours' => Dispatch::where('created_at', '>=', $day)->distinct()->count('user_id'),
                'Dispatches, last 24 hours' => Dispatch::where('created_at', '>=', $day)->count(),
                'Weekly posters' => Dispatch::where('created_at', '>=', $week)->distinct()->count('user_id'),
                'Scheduled' => Dispatch::where('status', 'scheduled')->count(),
                'Jobs waiting' => DB::table('jobs')->count(),
            ],
            'todo' => Attention::items(),
            'failed' => DB::table('failed_jobs')->latest('failed_at')->take(25)->get(),
        ]);
    }
}
