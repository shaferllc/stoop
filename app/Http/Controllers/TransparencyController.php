<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The public monthly moderation report.
 */
class TransparencyController extends Controller
{
    public function __invoke(Request $request): View
    {
        $month = rescue(fn () => Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth(), now()->startOfMonth(), false);
        $range = [$month, $month->copy()->endOfMonth()];

        $dispatches = Dispatch::withTrashed()->whereBetween('created_at', $range);
        $penalties = Penalty::whereBetween('created_at', $range);
        $appeals = Appeal::whereBetween('decided_at', $range);

        return view('transparency', [
            'month' => $month,
            'stats' => [
                'Dispatches filed' => (clone $dispatches)->count(),
                'Held by automatic screening' => (clone $dispatches)->where('status', 'held')->count(),
                'Hidden after reports, awaiting review' => (clone $dispatches)->where('status', 'hidden')->count(),
                'Removed by moderators' => (clone $dispatches)->where('status', 'removed')->count(),
                'Threads paused for pile-ons' => (clone $dispatches)->whereNotNull('replies_locked_until')->count(),
                'Warnings' => (clone $penalties)->where('type', 'warning')->count(),
                'Timeouts' => (clone $penalties)->where('type', 'timeout')->count(),
                'Bans' => (clone $penalties)->where('type', 'ban')->count(),
                'Appeals granted' => (clone $appeals)->where('status', 'granted')->count(),
                'Appeals denied' => (clone $appeals)->where('status', 'denied')->count(),
            ],
            'reports' => Report::whereBetween('created_at', $range)->selectRaw('reason, count(*) as total')->groupBy('reason')->pluck('total', 'reason'),
        ]);
    }
}
