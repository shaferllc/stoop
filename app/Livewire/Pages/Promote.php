<?php

namespace App\Livewire\Pages;

use App\Models\Dispatch;
use App\Models\PlaceDeal;
use App\Support\Promotions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Promote a deal, happening or dispatch: pick where it shows, when, and a budget, then pay once
 * with Stripe Checkout (or use the Business plan's free monthly happening).
 */
#[Layout('layouts::stoop')]
#[Title('Promote')]
class Promote extends Component
{
    #[Locked]
    public string $kind;

    #[Locked]
    public int $id;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(string $kind, int $id): void
    {
        $this->kind = $kind;
        $this->id = $id;
        abort_unless(Promotions::kindFor(Auth::user(), $this->target()), 403);
        $tz = config('stoop.timezone');
        $this->form = ['stops' => array_filter([$this->target() instanceof PlaceDeal ? $this->target()->place->stop : $this->target()->stop]),
            'lines' => [], 'starts_on' => now($tz)->toDateString(), 'ends_on' => now($tz)->addDays(6)->toDateString(), 'tier' => 'small'];
    }

    protected function target(): PlaceDeal|Dispatch
    {
        return $this->kind === 'deal' ? PlaceDeal::with('place')->findOrFail($this->id) : Dispatch::findOrFail($this->id);
    }

    public function pay(): mixed
    {
        abort_if(RateLimiter::tooManyAttempts('promote:'.Auth::id(), 10), 429);
        RateLimiter::hit('promote:'.Auth::id());
        $this->resetErrorBag();
        try {
            $url = Promotions::start(Auth::user(), $this->target(), $this->form);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.$field", $messages[0]);
            }

            return null;
        }

        return $url ? redirect()->away($url) : $this->redirectRoute('business.edit', ['promoted' => 'free'], navigate: true);
    }

    public function render(): View
    {
        $target = $this->target();

        return view('livewire.pages.promote', [
            'target' => $target,
            'promoKind' => Promotions::kindFor(Auth::user(), $target),
            'free' => Promotions::kindFor(Auth::user(), $target) === 'happening' && Promotions::freeLeft(Auth::user()),
        ]);
    }
}
