<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Support\Collection;

/**
 * Automatic strikes for posts the automatic check removes. The ladder counts strikes in
 * the last 90 days: a warning, then a 1-day timeout, then 7 days, then 30 days. From the
 * fourth strike on, the account is flagged for a person to decide on a ban. Bans are
 * never automatic.
 */
class Strikes
{
    public const WINDOW_DAYS = 90;

    /** Hours of timeout by strike number; strike 1 is a warning. */
    public const LADDER = [2 => 24, 3 => 168, 4 => 720];

    public static function recent(User $user): int
    {
        return $user->penalties()->whereNull('lifted_at')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))->count();
    }

    public static function issue(User $user, string $reason, ?Dispatch $dispatch = null): Penalty
    {
        $strike = self::recent($user) + 1;
        $hours = self::LADDER[min($strike, 4)] ?? null;

        $penalty = Penalty::create([
            'user_id' => $user->id,
            'subject_hash' => $user->subjectHash(),
            'type' => $hours ? 'timeout' : 'warning',
            'reason' => $reason,
            'dispatch_id' => $dispatch?->id,
            'moderator_id' => null,
            'ends_at' => $hours ? now()->addHours($hours) : null,
        ]);

        Audit::log('strike.automatic', $user, ['strike' => $strike, 'type' => $penalty->type, 'hours' => $hours, 'reason' => $reason]);

        if ($strike >= 4) {
            Audit::log('ban.recommended', $user, ['strikes' => $strike]);
        }

        $user->notify(new ModerationNotice(match (true) {
            ! $hours => 'Your dispatch was removed automatically: '.$reason.'. This is a warning. You can appeal if we got it wrong.',
            default => 'Your dispatch was removed automatically: '.$reason.'. That’s strike '.$strike.' in '.self::WINDOW_DAYS.' days, so you’re in a timeout for '.Penalty::TIMEOUTS[$hours].'. You can appeal once.',
        }, $dispatch));

        return $penalty;
    }

    /**
     * Accounts with four or more strikes in the window and no ban yet, for a person to review.
     *
     * @return Collection<int, User>
     */
    public static function banCandidates(): Collection
    {
        $hashes = Penalty::whereNull('lifted_at')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('subject_hash')->havingRaw('count(*) >= 4')->pluck('subject_hash');
        $banned = Penalty::active()->where('type', 'ban')->whereIn('subject_hash', $hashes)->pluck('subject_hash');

        return User::whereIn('id', Penalty::whereIn('subject_hash', $hashes->diff($banned))->whereNotNull('user_id')->pluck('user_id'))->get();
    }
}
