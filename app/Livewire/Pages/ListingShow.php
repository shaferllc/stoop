<?php

namespace App\Livewire\Pages;

use App\Models\Listing;
use App\Support\Classifieds;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One listing. Its poster can renew it, mark it filled or sold, or pay for it; everyone else
 * can message the poster or report it. Staff can see listings that aren't live.
 */
#[Layout('layouts::stoop')]
class ListingShow extends Component
{
    #[Locked]
    public int $listingId;

    public string $message = '';

    public bool $reporting = false;

    public string $reason = 'scam';

    public string $note = '';

    public function mount(Listing $listing): void
    {
        $user = Auth::user();
        abort_unless($listing->isLive() || $user?->id === $listing->user_id || $user?->hasRole('moderator'), 404);
        $this->listingId = $listing->id;
    }

    protected function listing(): Listing
    {
        return Listing::with('user')->findOrFail($this->listingId);
    }

    public function contact(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->validate(['message' => ['required', 'string', 'min:2', 'max:1000']]);
        try {
            $conversation = Classifieds::contact(Auth::user(), $this->listing(), $this->message);
        } catch (ValidationException $e) {
            $this->addError('message', collect($e->errors())->flatten()->first());

            return;
        }

        $this->redirectRoute('messages.show', $conversation, navigate: true);
    }

    public function report(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        Classifieds::report(Auth::user(), $this->listing(), $this->reason, $this->note ?: null);
        $this->reset('reporting', 'note');
        Flux::toast('Thanks. A moderator will take a look.');
    }

    public function renew(): void
    {
        $listing = $this->listing();
        if ($checkout = Classifieds::renew(Auth::user(), $listing)) {
            $this->redirect($checkout);

            return;
        }
        Flux::toast('Renewed for '.config('classifieds.days').' more days.');
    }

    public function pay(): void
    {
        $listing = $this->listing();
        abort_unless($listing->user_id === Auth::id() && $listing->status === 'unpaid', 403);
        $this->redirect(Classifieds::checkout($listing));
    }

    public function markFilled(): void
    {
        Classifieds::markFilled(Auth::user(), $this->listing());
        Flux::toast('Marked as done. It’s off the board.');
    }

    public function render(): View
    {
        $listing = $this->listing();

        return view('livewire.pages.listing-show', [
            'listing' => $listing,
            'mine' => Auth::id() === $listing->user_id,
            'stripe' => Classifieds::stripeReady(),
        ])->title($listing->title.' · Classifieds');
    }
}
