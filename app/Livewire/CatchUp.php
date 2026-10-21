<?php

namespace App\Livewire;

use App\Support\CatchUp as Digest;
use App\Support\Claude;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Catch me up" button on stop pages and line-filtered feeds.
 */
class CatchUp extends Component
{
    #[Locked]
    public ?string $stop = null;

    #[Locked]
    public ?string $line = null;

    public bool $open = false;

    public function catchUp(): void
    {
        if (! RateLimiter::attempt('catch-up:'.(Auth::id() ?? request()->ip()), 10, fn () => true)) {
            return;
        }

        $this->open = true;
    }

    public function render(): View
    {
        return view('livewire.catch-up', [
            'digest' => $this->open ? Digest::for($this->stop, $this->line) : null,
            'aiOn' => Claude::enabled(),
        ]);
    }
}
