<?php

namespace App\Support;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\PlaceClaim;

/**
 * What staff need to look at, for the admin overview and the desktop app's badge.
 */
class Attention
{
    /**
     * @return array<string, array{label: string, count: int, url: string}>
     */
    public static function items(): array
    {
        return [
            'queue' => ['label' => 'Posts waiting for moderators', 'count' => Dispatch::whereIn('status', ['held', 'hidden'])->orWhereNotNull('flagged_at')->count(), 'url' => route('moderation')],
            'appeals' => ['label' => 'Open appeals', 'count' => Appeal::where('status', 'open')->count(), 'url' => route('moderation', ['view' => 'appeals'])],
            'messages' => ['label' => 'Reported messages', 'count' => MessageReport::whereNull('resolved_at')->distinct()->count('message_id'), 'url' => route('moderation', ['view' => 'messages'])],
            'bans' => ['label' => 'Accounts with 4+ strikes to review for a ban', 'count' => Strikes::banCandidates()->count(), 'url' => route('admin.users', ['filter' => 'ban_review'])],
            'claims' => ['label' => 'Place claims to check', 'count' => PlaceClaim::where('status', 'pending')->count(), 'url' => route('admin.places', ['tab' => 'claims'])],
        ];
    }
}
