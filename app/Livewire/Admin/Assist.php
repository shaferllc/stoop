<?php

namespace App\Livewire\Admin;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\ModerationAssist;
use App\Support\ModerationInsights;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Moderator help, loaded after the page: Claude's suggestion and past decisions on a queue
 * item ("dispatch"), notes on an appeal ("appeal"), or a brief on a person ("user").
 * Claude is only asked on mount, never on re-render, and its answers are cached.
 */
#[Lazy]
class Assist extends Component
{
    use StaffOnly;

    #[Locked]
    public string $kind;

    #[Locked]
    public int $subjectId;

    /** @var array<string, mixed> */
    #[Locked]
    public array $data = [];

    public function mount(): void
    {
        $this->data = match ($this->kind) {
            'dispatch' => [
                'suggestion' => ModerationAssist::suggestion(Dispatch::with('user')->findOrFail($this->subjectId)),
                'past' => ModerationInsights::pastDecisions(Dispatch::withTrashed()->findOrFail($this->subjectId)),
            ],
            'appeal' => ['notes' => ModerationAssist::appeal(Appeal::findOrFail($this->subjectId))],
            'user' => ModerationAssist::brief(User::findOrFail($this->subjectId)),
        };
    }

    public function placeholder(): string
    {
        return '<div class="mt-2 h-5 w-48 animate-pulse rounded bg-chip"></div>';
    }

    public function render(): View
    {
        return view('livewire.admin.assist', ['ai' => ModerationAssist::enabled()]);
    }
}
