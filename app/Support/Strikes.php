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

    /**
     * Hate speech skips the warning: the first strike is a 7-day timeout, the second 30 days
     * and flags the account for a ban.
     */
    public const SEVERE_LADDER = [1 => 168, 2 => 720];

    public static function recent(User $user): int
    {
        return $user->penalties()->whereNull('lifted_at')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))->count();
    }

    public static function issue(User $user, string $reason, ?Dispatch $dispatch = null, bool $severe = false): Penalty
    {
        $strike = self::recent($user) + 1;
        $hours = $severe ? self::SEVERE_LADDER[min($strike, 2)] : (self::LADDER[min($strike, 4)] ?? null);

        $penalty = Penalty::create([
            'user_id' => $user->id,
            'subject_hash' => $user->subjectHash(),
            'type' => $hours ? 'timeout' : 'warning',
            'reason' => $reason,
            'dispatch_id' => $dispatch?->id,
            'moderator_id' => null,
            'ends_at' => $hours ? now()->addHours($hours) : null,
        ]);

        Audit::log('strike.automatic', $user, ['strike' => $strike, 'type' => $penalty->type, 'hours' => $hours, 'reason' => $reason, 'severe' => $severe]);

        if ($strike >= 4 || ($severe && $strike >= 2)) {
            Audit::log('ban.recommended', $user, ['strikes' => $strike, 'hate_speech' => $severe]);
        }

        $user->notify(new ModerationNotice(match (true) {
            ! $hours => 'Your dispatch was removed automatically: '.$reason.'. This is a warning. You can appeal if we got it wrong.',
            $severe => 'Your dispatch was removed automatically: '.$reason.'. Stoop has zero tolerance for hate speech, so you’re in a timeout for '.Penalty::TIMEOUTS[$hours].($strike >= 2 ? ' and your account is under review for a ban' : '').'. You can appeal once.',
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
        $recent = Penalty::whereNull('lifted_at')->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS));
        $hashes = (clone $recent)->groupBy('subject_hash')->havingRaw('count(*) >= 4')->pluck('subject_hash')
            ->merge((clone $recent)->whereIn('reason', array_map(fn ($c) => config('stoop.openai_reasons.'.$c), config('stoop.hate_categories')))
                ->groupBy('subject_hash')->havingRaw('count(*) >= 2')->pluck('subject_hash'))
            ->unique();
        $banned = Penalty::active()->where('type', 'ban')->whereIn('subject_hash', $hashes)->pluck('subject_hash');

        return User::whereIn('id', Penalty::whereIn('subject_hash', $hashes->diff($banned))->whereNotNull('user_id')->pluck('user_id'))->get();
    }
}
