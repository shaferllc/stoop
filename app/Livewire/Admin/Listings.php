<?php

namespace App\Livewire\Admin;

use App\Models\Listing;
use App\Notifications\ModerationNotice;
use App\Support\Audit;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Classified listings: the ones screening or reports held, and everything else for lookups.
 */
#[Layout('layouts::stoop')]
#[Title('Classifieds · Admin')]
class Listings extends Component
{
    use StaffOnly, WithPagination;

    public const ROLE = 'moderator';

    #[Url(except: 'held')]
    public string $tab = 'held';

    public function approve(int $id): void
    {
        $listing = Listing::whereIn('status', ['held', 'removed'])->findOrFail($id);
        $listing->forceFill(['status' => 'active', 'expires_at' => $listing->expires_at?->isFuture() ? $listing->expires_at : now()->addDays(config('classifieds.days'))])->save();
        $listing->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        Audit::log('listing.approved', $listing);
    }

    public function remove(int $id): void
    {
        $listing = Listing::findOrFail($id);
        $listing->forceFill(['status' => 'removed'])->save();
        $listing->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
        $listing->user->notify(new ModerationNotice('Your listing “'.$listing->title.'” was taken down for breaking the house rules.'.($listing->paid_cents ? ' Reply to the magazine’s team about a refund.' : '')));
        Audit::log('listing.removed', $listing);
    }

    public function render(): View
    {
        $query = Listing::with('user')->withCount(['reports' => fn ($q) => $q->whereNull('resolved_at')]);

        return view('livewire.admin.listings', [
            'listings' => (match ($this->tab) {
                'reported' => $query->whereHas('reports', fn ($q) => $q->whereNull('resolved_at')),
                'all' => $query,
                default => $query->where('status', 'held'),
            })->latest()->paginate(30),
            'held' => Listing::where('status', 'held')->count(),
            'revenue' => Listing::where('created_at', '>=', now()->subDays(30))->sum('paid_cents'),
        ]);
    }
}
