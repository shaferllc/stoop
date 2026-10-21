<?php

namespace App\Livewire;

use App\Models\Dispatch;
use App\Support\Happenings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The host's panel on their Happening: who's coming, the calendar start time, and tickets
 * (place owners and staff only). Staff can feature it.
 */
class HappeningHost extends Component
{
    #[Locked]
    public int $dispatchId;

    /** @var array<string, string> */
    public array $form = ['starts_at' => '', 'price' => '', 'ticket_url' => ''];

    public function mount(int $dispatchId): void
    {
        $this->dispatchId = $dispatchId;
        $h = $this->happening()->happening;
        $this->form = [
            'starts_at' => $h?->starts_at?->timezone(config('stoop.timezone'))->format('Y-m-d\TH:i') ?? '',
            'price' => $h?->price_cents !== null ? (string) ($h->price_cents / 100) : '',
            'ticket_url' => $h?->ticket_url ?? '',
        ];
    }

    /**
     * The happening, for its host or staff only. Checked on every request.
     */
    protected function happening(): Dispatch
    {
        $dispatch = Dispatch::where('type', 'happening')->whereNull('parent_id')->findOrFail($this->dispatchId);
        abort_unless(Auth::id() === $dispatch->user_id || Auth::user()?->is_staff, 403);

        return $dispatch;
    }

    public function save(): void
    {
        $user = Auth::user();
        $input = array_map(fn ($v) => $v === '' ? null : $v, $this->form);

        Happenings::saveDetails($user, $this->happening(), Happenings::canTicket($user) ? $input : ['starts_at' => $input['starts_at']]);
        Flux::toast('Saved.');
    }

    public function toggleFeatured(): void
    {
        $dispatch = $this->happening();
        $happening = Happenings::feature(Auth::user(), $dispatch, ! $dispatch->happening?->featured);
        Flux::toast($happening->featured ? 'Featured.' : 'No longer featured.');
    }

    public function render(): View
    {
        $dispatch = $this->happening();

        return view('livewire.happening-host', [
            'happening' => $dispatch->happening,
            'rsvps' => Happenings::rsvps($dispatch),
            'canTicket' => Happenings::canTicket(Auth::user()),
        ]);
    }
}
