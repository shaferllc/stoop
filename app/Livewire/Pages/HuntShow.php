<?php

namespace App\Livewire\Pages;

use App\Models\Hunt;
use App\Models\HuntStep;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A hunt's clues, the player's progress and the leaderboard. Answers and code words stay on
 * the server: the page only gets the clue text.
 */
#[Layout('layouts::stoop')]
class HuntShow extends Component
{
    #[Locked]
    public int $huntId;

    /** @var array<int, string> Code words typed per step id. */
    public array $codes = [];

    public function mount(Hunt $hunt): void
    {
        $this->huntId = $hunt->id;
    }

    public function checkIn(int $stepId): void
    {
        if (! $user = Auth::user()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->resetErrorBag();

        // Slows down guessing code words.
        if (! RateLimiter::attempt('hunt:'.$user->id, 10, fn () => true)) {
            $this->addError("codes.$stepId", 'Too many tries. Wait a minute.');

            return;
        }

        $step = HuntStep::where('hunt_id', $this->huntId)->findOrFail($stepId);
        if ($reason = $step->complete($user, $this->codes[$stepId] ?? null)) {
            $this->addError("codes.$stepId", $reason);

            return;
        }

        unset($this->codes[$stepId]);
        Flux::toast('Found it! Your passport has a new stamp.', variant: 'success');
    }

    public function render(): View
    {
        $hunt = Hunt::with('steps')->findOrFail($this->huntId);

        return view('livewire.pages.hunt-show', [
            'hunt' => $hunt,
            'done' => Auth::check() ? DB::table('hunt_completions')->where('user_id', Auth::id())
                ->whereIn('hunt_step_id', $hunt->steps->pluck('id'))->pluck('created_at', 'hunt_step_id')->all() : [],
            'leaders' => $hunt->leaderboard(),
        ])->title($hunt->name);
    }
}
