<?php

namespace App\Livewire\Pages;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\Penalty;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Moderation')]
class Moderation extends Component
{
    #[Url(except: 'queue')]
    public string $view = 'queue';

    /** The dispatch a moderator is acting on, with the reason and penalty they picked. */
    public ?int $acting = null;

    public string $reason = '';

    public string $note = '';

    public string $penalty = 'none';

    public function mount(): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
    }

    protected function moderator(): User
    {
        abort_unless(Auth::user()?->is_staff, 403);

        return Auth::user();
    }

    /**
     * Put a dispatch back up and close its reports. Approvals don't notify anyone.
     */
    public function approve(int $id): void
    {
        $moderator = $this->moderator();
        $dispatch = Dispatch::findOrFail($id);
        $dispatch->forceFill(['status' => 'approved', 'flagged_at' => null, 'moderated_by' => $moderator->id])->save();
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Audit::log('dispatch.approved', $dispatch);
        Flux::toast('Approved.');
    }

    public function startAction(int $id): void
    {
        $this->moderator();
        $this->acting = $id;
        $this->reason = config('stoop.rule_reasons.0');
        $this->note = '';
        $this->penalty = 'none';
    }

    /**
     * Remove a dispatch, tell the author why, and optionally apply the next step on the penalty ladder.
     */
    public function remove(): void
    {
        $moderator = $this->moderator();

        $this->validate([
            'reason' => ['required', Rule::in(config('stoop.rule_reasons'))],
            'note' => ['nullable', 'string', 'max:200'],
            'penalty' => ['required', Rule::in(['none', 'warning', 'ban', ...array_map('strval', array_keys(Penalty::TIMEOUTS))])],
        ]);

        $dispatch = Dispatch::with('user')->findOrFail($this->acting);
        $why = trim($this->reason.($this->note ? '. '.$this->note : ''));

        $dispatch->forceFill(['status' => 'removed', 'flagged_at' => null, 'moderated_by' => $moderator->id, 'moderation_reason' => $why])->save();
        $dispatch->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        $dispatch->user->notify(new ModerationNotice('A moderator removed your dispatch: '.$why.'. You can appeal once.', $dispatch));
        Audit::log('dispatch.removed', $dispatch, ['reason' => $why, 'penalty' => $this->penalty]);

        if ($this->penalty !== 'none') {
            $this->penalize($dispatch->user, $this->penalty, $why, $dispatch, $moderator);
        }

        $this->acting = null;
        Flux::toast('Removed.');
    }

    protected function penalize(User $user, string $type, string $reason, ?Dispatch $dispatch, User $moderator): void
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

        $user->notify(new ModerationNotice(match ($penalty->type) {
            'warning' => 'You received a warning: '.$reason.'.',
            'ban' => 'Your account was banned: '.$reason.'.',
            default => 'You’re in a timeout for '.Penalty::TIMEOUTS[$hours].': '.$reason.'.',
        }.($penalty->type === 'warning' ? '' : ' You can appeal once.'), $dispatch));
    }

    /**
     * Close a message report, optionally putting the sender in a timeout.
     */
    public function resolveMessageReport(int $reportId, int $timeoutHours = 0): void
    {
        $moderator = $this->moderator();
        $report = MessageReport::with('message.user')->findOrFail($reportId);
        MessageReport::where('message_id', $report->message_id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Audit::log('message_report.resolved', $report, ['timeout_hours' => $timeoutHours]);

        if ($timeoutHours && $sender = $report->message->user) {
            abort_unless(isset(Penalty::TIMEOUTS[$timeoutHours]), 422);
            $this->penalize($sender, (string) $timeoutHours, config('stoop.report_reasons.'.$report->reason, 'Breaking the house rules in messages'), null, $moderator);
        }

        Flux::toast('Resolved.');
    }

    public function lift(int $penaltyId): void
    {
        $this->moderator();
        $penalty = Penalty::findOrFail($penaltyId);
        $penalty->update(['lifted_at' => now()]);
        Audit::log('penalty.lifted', $penalty);
        Flux::toast('Lifted.');
    }

    /**
     * Appeals go to a different moderator than the one who made the call.
     */
    public function decide(int $appealId, bool $grant): void
    {
        $moderator = $this->moderator();
        $appeal = Appeal::with(['penalty', 'dispatch'])->where('status', 'open')->findOrFail($appealId);
        abort_if($appeal->originalModeratorId() === $moderator->id, 403);

        $appeal->update(['status' => $grant ? 'granted' : 'denied', 'decided_by' => $moderator->id, 'decided_at' => now()]);
        Audit::log($grant ? 'appeal.granted' : 'appeal.denied', $appeal);

        if ($grant) {
            $appeal->penalty?->update(['lifted_at' => now()]);
            $appeal->dispatch?->forceFill(['status' => 'approved', 'moderation_reason' => null])->save();
        }

        $appeal->user->notify(new ModerationNotice($grant
            ? 'Your appeal was granted. We’ve reversed the decision.'
            : 'Your appeal was reviewed by a second moderator and the decision stands.', $appeal->dispatch));

        Flux::toast($grant ? 'Appeal granted.' : 'Appeal denied.');
    }

    public function render(): View
    {
        $moderator = Auth::user();

        $queue = Dispatch::with(['user', 'reports.user'])
            ->where(fn ($q) => $q->whereIn('status', ['held', 'hidden'])
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '<', now()->subMinutes(10)))
                ->orWhereNotNull('flagged_at')
                ->orWhereHas('reports', fn ($q) => $q->whereNull('resolved_at')))
            ->where('status', '!=', 'removed')
            ->latest()->take(50)->get()
            ->each(fn ($d) => $d->strikes = $d->user->penalties()->count());

        return view('livewire.pages.moderation', [
            'queue' => $queue,
            'appeals' => Appeal::with(['user', 'penalty', 'dispatch'])->where('status', 'open')->oldest()->get()
                ->reject(fn ($a) => $a->originalModeratorId() === $moderator->id),
            'penalties' => Penalty::with('user')->active()->whereIn('type', ['timeout', 'ban'])->latest()->get(),
            'messageReports' => MessageReport::with(['user', 'message.user'])->whereNull('resolved_at')->oldest()->get()
                ->unique('message_id')
                ->each(fn ($r) => $r->context = $r->message->conversation->messages()->with('user')
                    ->whereBetween('id', [$r->message_id - 50, $r->message_id + 50])->orderBy('id')->get()
                    ->filter(fn ($m) => abs($m->id - $r->message_id) <= 3 || $m->id === $r->message_id)),
            'actingOn' => $this->acting ? Dispatch::with('user')->find($this->acting) : null,
        ]);
    }
}
