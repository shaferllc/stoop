<?php

namespace App\Livewire\Pages;

use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\Penalty;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Appeal')]
class AppealCreate extends Component
{
    #[Url, Locked]
    public ?int $dispatch = null;

    #[Url, Locked]
    public ?int $penalty = null;

    public string $body = '';

    public function mount(): void
    {
        abort_unless($this->target(), 404);
    }

    /**
     * The removed dispatch or the penalty being appealed, if it belongs to this person.
     */
    protected function target(): Dispatch|Penalty|null
    {
        $user = Auth::user();

        if ($this->penalty) {
            return $user->penalties()->whereIn('type', ['timeout', 'ban'])->find($this->penalty);
        }

        return $user->dispatches()->where('status', 'removed')->find($this->dispatch);
    }

    public function submit(): void
    {
        $this->validate(['body' => ['required', 'string', 'min:10', 'max:1000']]);
        $target = $this->target();
        abort_unless($target, 404);

        $already = Appeal::where($target instanceof Penalty ? 'penalty_id' : 'dispatch_id', $target->id)->exists();
        if ($already) {
            $this->addError('body', 'You’ve already appealed this decision. Each decision can be appealed once.');

            return;
        }

        Appeal::create([
            'user_id' => Auth::id(),
            'penalty_id' => $target instanceof Penalty ? $target->id : null,
            'dispatch_id' => $target instanceof Dispatch ? $target->id : $target->dispatch_id,
            'body' => $this->body,
        ]);

        Flux::toast('Appeal sent. A different moderator will review it.');
        $this->redirectRoute('notifications', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.pages.appeal-create', ['target' => $this->target()]);
    }
}
