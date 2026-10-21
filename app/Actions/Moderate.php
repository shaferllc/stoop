<?php

namespace App\Actions;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\Penalty;
use App\Models\User;
use App\Notifications\ModerationMail;
use App\Notifications\ModerationNotice;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Moderator decisions, shared by the moderation page and the admin API.
 */
class Moderate
{
    /**
     * @return array<string, mixed>
     */
    public static function penaltyRules(): array
    {
        return ['required', Rule::in(['none', 'warning', 'ban', ...array_map('strval', array_keys(Penalty::TIMEOUTS))])];
    }

    /**
     * @return Collection<int, Dispatch>
     */
    public static function queue(): Collection
    {
        return Dispatch::with(['user', 'reports.user'])
            ->where(fn ($q) => $q->whereIn('status', ['held', 'hidden'])
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '<', now()->subMinutes(10)))
                ->orWhereNotNull('flagged_at')
                ->orWhereHas('reports', fn ($q) => $q->whereNull('resolved_at')))
            ->where('status', '!=', 'removed')
            ->latest()->take(50)->get()
            ->each(fn ($d) => $d->strikes = $d->user->penalties()->count());
    }

    /**
     * Open appeals, minus the ones this moderator made the original call on.
     *
     * @return Collection<int, Appeal>
     */
    public static function appeals(User $moderator): Collection
    {
        return Appeal::with(['user', 'penalty', 'dispatch'])->where('status', 'open')->oldest()->get()
            ->reject(fn ($a) => $a->originalModeratorId() === $moderator->id)->values();
    }

    /**
     * @return Collection<int, MessageReport>
     */
    public static function messageReports(): Collection
    {
        return MessageReport::with(['user', 'message.user'])->whereNull('resolved_at')->oldest()->get()
            ->unique('message_id')->values()
            ->each(fn ($r) => $r->context = $r->message->conversation->messages()->with('user')
                ->whereBetween('id', [$r->message_id - 50, $r->message_id + 50])->orderBy('id')->get()
                ->filter(fn ($m) => abs($m->id - $r->message_id) <= 3 || $m->id === $r->message_id)->values());
    }

    /**
     * Put a dispatch back up and close its reports. Approvals don't notify anyone.
     */
    public static function approve(Dispatch $dispatch, User $moderator): void
    {
        $dispatch->forceFill(['status' => 'approved', 'flagged_at' => null, 'moderated_by' => $moderator->id])->save();
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Audit::log('dispatch.approved', $dispatch);
    }

    /**
     * Remove a dispatch, tell the author why, and optionally apply the next step on the penalty ladder.
     */
    public static function remove(Dispatch $dispatch, User $moderator, string $reason, ?string $note, string $penalty): void
    {
        $why = trim($reason.($note ? '. '.$note : ''));

        $dispatch->forceFill(['status' => 'removed', 'flagged_at' => null, 'moderated_by' => $moderator->id, 'moderation_reason' => $why])->save();
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        $dispatch->user->notify(new ModerationNotice('A moderator removed your dispatch: '.$why.'. You can appeal once.', $dispatch));
        Audit::log('dispatch.removed', $dispatch, ['reason' => $why, 'penalty' => $penalty]);

        if ($penalty !== 'none') {
            self::penalize($dispatch->user, $penalty, $why, $dispatch, $moderator);
        }
    }

    /**
     * @param  string  $type  'warning', 'ban', or a timeout length in hours
     */
    public static function penalize(User $user, string $type, string $reason, ?Dispatch $dispatch, User $moderator): Penalty
    {
        $hours = is_numeric($type) ? (int) $type : null;

        $penalty = Penalty::create([
            'user_id' => $user->id,
            'subject_hash' => $user->subjectHash(),
            'type' => $hours ? 'timeout' : $type,
            'reason' => $reason,
            'dispatch_id' => $dispatch?->id,
            'moderator_id' => $moderator->id,
            'ends_at' => $hours ? now()->addHours($hours) : null,
        ]);
        Audit::log('penalty.issued', $user, ['type' => $penalty->type, 'hours' => $hours, 'reason' => $reason]);

        $message = match ($penalty->type) {
            'warning' => 'You received a warning: '.$reason.'.',
            'ban' => 'Your account was banned: '.$reason.'.',
            default => 'You’re in a timeout for '.Penalty::TIMEOUTS[$hours].': '.$reason.'.',
        }.($penalty->type === 'warning' ? '' : ' You can appeal once.');
        $user->notify(new ModerationNotice($message, $dispatch));

        // Timeouts and bans are emailed too, in case the person isn't on the site.
        if ($penalty->type !== 'warning') {
            $user->notify(new ModerationMail($penalty->type === 'ban' ? 'Your Stoop account was banned' : 'You’re in a timeout on Stoop', [$message], route('appeals.create'), 'Appeal'));
        }

        return $penalty;
    }

    public static function lift(Penalty $penalty): void
    {
        $penalty->update(['lifted_at' => now()]);
        Audit::log('penalty.lifted', $penalty);
    }

    /**
     * Close a message report, optionally putting the sender in a timeout.
     */
    public static function resolveMessageReport(MessageReport $report, User $moderator, int $timeoutHours = 0): void
    {
        abort_unless($timeoutHours === 0 || isset(Penalty::TIMEOUTS[$timeoutHours]), 422);
        MessageReport::where('message_id', $report->message_id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Audit::log('message_report.resolved', $report, ['timeout_hours' => $timeoutHours]);

        if ($timeoutHours && $sender = $report->message->user) {
            self::penalize($sender, (string) $timeoutHours, config('stoop.report_reasons.'.$report->reason, 'Breaking the house rules in messages'), null, $moderator);
        }
    }

    /**
     * Appeals go to a different moderator than the one who made the call.
     */
    public static function decide(Appeal $appeal, User $moderator, bool $grant): void
    {
        abort_unless($appeal->status === 'open', 422);
        abort_if($appeal->originalModeratorId() === $moderator->id, 403, 'A different moderator has to decide this appeal.');

        $appeal->update(['status' => $grant ? 'granted' : 'denied', 'decided_by' => $moderator->id, 'decided_at' => now()]);
        Audit::log($grant ? 'appeal.granted' : 'appeal.denied', $appeal);

        if ($grant) {
            $appeal->penalty?->update(['lifted_at' => now()]);
            $appeal->dispatch?->forceFill(['status' => 'approved', 'moderation_reason' => null])->save();
        }

        $message = $grant
            ? 'Your appeal was granted. We’ve reversed the decision.'
            : 'Your appeal was reviewed by a second moderator and the decision stands.';
        $appeal->user->notify(new ModerationNotice($message, $appeal->dispatch));
        $appeal->user->notify(new ModerationMail('Your Stoop appeal was decided', [$message]));
    }
}
