<?php

namespace App\Livewire\Admin;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\PlaceClaim;
use App\Models\User;
use App\Support\Strikes;
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
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Flux::toast('Queued again.');
    }

    public function forget(string $uuid): void
    {
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
            'todo' => [
                ['Posts waiting for moderators', Dispatch::whereIn('status', ['held', 'hidden'])->orWhereNotNull('flagged_at')->count(), route('moderation')],
                ['Open appeals', Appeal::where('status', 'open')->count(), route('moderation', ['view' => 'appeals'])],
                ['Reported messages', MessageReport::whereNull('resolved_at')->distinct()->count('message_id'), route('moderation', ['view' => 'messages'])],
                ['Accounts with 4+ strikes to review for a ban', Strikes::banCandidates()->count(), route('admin.users', ['filter' => 'ban_review'])],
                ['Place claims to check', PlaceClaim::where('status', 'pending')->count(), route('admin.places', ['tab' => 'claims'])],
            ],
            'failed' => DB::table('failed_jobs')->latest('failed_at')->take(25)->get(),
        ]);
    }
}
