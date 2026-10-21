<?php

namespace App\Livewire\Pages;

use App\Support\Achievements;
use App\Support\Bingo;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Metro Bingo')]
class MetroBingo extends Component
{
    /** The hand-marked square being proved, and the post picked as proof. */
    public ?string $marking = null;

    public ?int $proofId = null;

    public function startMarking(string $square): void
    {
        $this->marking = $square;
        $this->proofId = null;
    }

    public function mark(): void
    {
        $this->resetErrorBag();
        $user = Auth::user();
        $badgesBefore = Achievements::unlocked($user);

        if ($reason = Bingo::mark($user, (string) $this->marking, (int) $this->proofId)) {
            $this->addError('proofId', $reason);

            return;
        }

        $this->reset('marking', 'proofId');
        $unlocked = array_diff_key(Achievements::unlocked($user->fresh()), $badgesBefore);
        Flux::toast(isset($unlocked['bingo']) ? 'Bingo! The Metro Bingo badge is yours.' : 'Marked.', variant: isset($unlocked['bingo']) ? 'success' : null);
        if ($unlocked) {
            $this->dispatch('confetti');
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $card = Bingo::card($user);

        return view('livewire.pages.bingo', [
            'card' => $card,
            'proofPosts' => $this->marking ? Bingo::proofPosts($user, $card['week']) : collect(),
        ]);
    }
}
