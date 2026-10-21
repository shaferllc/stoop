<?php

namespace App\Livewire\Pages;

use App\Models\Ballot;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class BallotShow extends Component
{
    #[Locked]
    public int $ballotId;

    public function mount(Ballot $ballot): void
    {
        $this->ballotId = $ballot->id;
    }

    public function vote(int $nomineeId): void
    {
        abort_unless(Auth::user()?->hasVerifiedEmail(), 403);

        $error = Ballot::findOrFail($this->ballotId)->vote(Auth::user(), $nomineeId);
        $error ? Flux::toast($error, variant: 'danger') : Flux::toast('Vote counted.');
    }

    public function render(): View
    {
        $ballot = Ballot::findOrFail($this->ballotId);

        return view('livewire.pages.ballot-show', [
            'ballot' => $ballot,
            'categories' => $ballot->categories(Auth::user()),
        ])->title($ballot->title);
    }
}
