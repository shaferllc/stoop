<?php

namespace App\Livewire\Admin;

use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class UserShow extends Component
{
    use StaffOnly;

    #[Locked]
    public int $userId;

    public string $note = '';

    public string $subscribedUntil = '';

    public string $penaltyType = 'warning';

    public string $penaltyReason = '';

    public function mount(User $user): void
    {
        $this->userId = $user->id;
        $this->subscribedUntil = (string) $user->subscribed_until?->toDateString();
        $this->penaltyReason = config('stoop.rule_reasons.0');
    }

    protected function subject(): User
    {
        return User::findOrFail($this->userId);
    }

    public function toggleStaff(): void
    {
        $user = $this->subject();
        abort_if($user->id === $this->staff()->id, 422, 'You can’t remove your own staff access.');
        $user->forceFill(['is_staff' => ! $user->is_staff])->save();
        Audit::log($user->is_staff ? 'user.made_staff' : 'user.removed_staff', $user);
        Flux::toast($user->is_staff ? 'Now staff.' : 'No longer staff.');
    }

    public function verify(): void
    {
        $user = $this->subject();
        $user->forceFill(['email_verified_at' => now()])->save();
        Audit::log('user.verified', $user);
        Flux::toast('Verified.');
    }

    public function saveSubscription(): void
    {
        $this->validate(['subscribedUntil' => ['nullable', 'date']]);
        $user = $this->subject();
        $user->forceFill(['subscribed_until' => $this->subscribedUntil ? Carbon::parse($this->subscribedUntil)->endOfDay() : null])->save();
        Audit::log('user.subscription_set', $user, ['until' => $this->subscribedUntil ?: null]);
        Flux::toast('Subscription saved.');
    }

    public function addNote(): void
    {
        $this->validate(['note' => ['required', 'string', 'max:2000']]);
        DB::table('user_notes')->insert(['user_id' => $this->userId, 'author_id' => $this->staff()->id, 'body' => $this->note, 'created_at' => now(), 'updated_at' => now()]);
        $this->reset('note');
    }

    public function penalize(): void
    {
        $this->validate([
            'penaltyType' => ['required', Rule::in(['warning', 'ban', ...array_map('strval', array_keys(Penalty::TIMEOUTS))])],
            'penaltyReason' => ['required', Rule::in(config('stoop.rule_reasons'))],
        ]);

        $user = $this->subject();
        $hours = is_numeric($this->penaltyType) ? (int) $this->penaltyType : null;
        $penalty = Penalty::create([
            'user_id' => $user->id, 'subject_hash' => $user->subjectHash(), 'moderator_id' => $this->staff()->id,
            'type' => $hours ? 'timeout' : $this->penaltyType, 'reason' => $this->penaltyReason,
            'ends_at' => $hours ? now()->addHours($hours) : null,
        ]);

        $user->notify(new ModerationNotice(match ($penalty->type) {
            'warning' => 'You received a warning: '.$this->penaltyReason.'.',
            'ban' => 'Your account was banned: '.$this->penaltyReason.'. You can appeal once.',
            default => 'You’re in a timeout for '.Penalty::TIMEOUTS[$hours].': '.$this->penaltyReason.'. You can appeal once.',
        }));
        Audit::log('penalty.issued', $user, ['type' => $penalty->type, 'hours' => $hours, 'reason' => $this->penaltyReason]);
        Flux::toast('Done.');
    }

    public function lift(int $penaltyId): void
    {
        $penalty = $this->subject()->penalties()->findOrFail($penaltyId);
        $penalty->update(['lifted_at' => now()]);
        Audit::log('penalty.lifted', $penalty);
    }

    public function deleteAccount(): void
    {
        $user = $this->subject();
        abort_if($user->id === $this->staff()->id || $user->is_bot, 422);
        Audit::log('user.deleted', $user, ['username' => $user->username]);
        $user->delete();
        $this->redirectRoute('admin.users', navigate: true);
    }

    public function render(): View
    {
        $user = User::withCount(['dispatches', 'followers', 'following'])->findOrFail($this->userId);

        return view('livewire.admin.user-show', [
            'user' => $user,
            'notes' => DB::table('user_notes')->leftJoin('users', 'users.id', '=', 'user_notes.author_id')
                ->where('user_notes.user_id', $user->id)->latest('user_notes.created_at')
                ->get(['user_notes.body', 'user_notes.created_at', 'users.username as author']),
            'penalties' => $user->penalties()->latest()->get(),
            'recent' => Dispatch::withTrashed()->where('user_id', $user->id)->latest()->take(20)->get(),
            'reportsAgainst' => DB::table('reports')->join('dispatches', 'dispatches.id', '=', 'reports.dispatch_id')->where('dispatches.user_id', $user->id)->count(),
            'audit' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
                ->where('subject_type', 'User')->where('subject_id', $user->id)->latest('audit_logs.created_at')->take(20)
                ->get(['audit_logs.action', 'audit_logs.created_at', 'users.username as actor']),
        ])->title($user->name.' · Admin');
    }
}
