<?php

namespace App\Livewire\Pages;

use App\Actions\Moderate;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\Penalty;
use App\Models\User;
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

    public function approve(int $id): void
    {
        Moderate::approve(Dispatch::findOrFail($id), $this->moderator());
        Flux::toast('Approved.');
    }

    /**
     * Opens the remove form, optionally pre-filled with Claude's suggested rule and penalty.
     */
    public function startAction(int $id, ?string $reason = null, string $penalty = 'none'): void
    {
        $this->moderator();
        $this->acting = $id;
        $this->reason = in_array($reason, config('stoop.rule_reasons'), true) ? $reason : config('stoop.rule_reasons.0');
        $this->note = '';
        $this->penalty = $penalty;
    }

    public function remove(): void
    {
        $moderator = $this->moderator();

        $this->validate([
            'reason' => ['required', Rule::in(config('stoop.rule_reasons'))],
            'note' => ['nullable', 'string', 'max:200'],
            'penalty' => Moderate::penaltyRules(),
        ]);

        Moderate::remove(Dispatch::with('user')->findOrFail($this->acting), $moderator, $this->reason, $this->note, $this->penalty);

        $this->acting = null;
        Flux::toast('Removed.');
    }

    public function resolveMessageReport(int $reportId, int $timeoutHours = 0): void
    {
        Moderate::resolveMessageReport(MessageReport::with('message.user')->findOrFail($reportId), $this->moderator(), $timeoutHours);
        Flux::toast('Resolved.');
    }

    public function lift(int $penaltyId): void
    {
        $this->moderator();
        Moderate::lift(Penalty::findOrFail($penaltyId));
        Flux::toast('Lifted.');
    }

    public function decide(int $appealId, bool $grant): void
    {
        Moderate::decide(Appeal::with(['penalty', 'dispatch'])->where('status', 'open')->findOrFail($appealId), $this->moderator(), $grant);
        Flux::toast($grant ? 'Appeal granted.' : 'Appeal denied.');
    }

    public function render(): View
    {
        return view('livewire.pages.moderation', [
            'queue' => Moderate::queue(),
            'appeals' => Moderate::appeals(Auth::user()),
            'penalties' => Penalty::with('user')->active()->whereIn('type', ['timeout', 'ban'])->latest()->get(),
            'messageReports' => Moderate::messageReports(),
            'actingOn' => $this->acting ? Dispatch::with('user')->find($this->acting) : null,
        ]);
    }
}
