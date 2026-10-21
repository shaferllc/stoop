<?php

namespace App\Livewire\Concerns;

use App\Support\Ads;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * Feed ads: one slot after every Ads::EVERY dispatches. A slot's ad is picked (and counted)
 * the first time it renders, then kept, so polling and "Load more" don't count it again.
 * Needs the component's $stop, $line and $tab.
 */
trait ShowsAds
{
    /** @var array<int, string|false> Slot number => "kind:id", or false for no ad. */
    #[Locked]
    public array $adSlots = [];

    /**
     * The ad for a slot (1 = after the 10th dispatch), picking one the first time.
     */
    public function adAt(int $slot): ?Model
    {
        if (! array_key_exists($slot, $this->adSlots)) {
            $ad = Ads::pick($this->adStops(), $this->line, array_values(array_filter($this->adSlots)));
            $this->adSlots[$slot] = $ad ? Ads::kindOf($ad).':'.$ad->id : false;
        }

        if (! $this->adSlots[$slot]) {
            return null;
        }
        [$kind, $id] = explode(':', $this->adSlots[$slot]);

        return Ads::find($kind, (int) $id);
    }

    public function adWhy(Model $ad): string
    {
        return Ads::why($ad, $this->adStops(), $this->line);
    }

    public function hideAd(string $kind, int $id, ?string $reason = null): void
    {
        abort_unless(RateLimiter::attempt('ad-hide:'.(Auth::id() ?? request()->ip()), 30, fn () => true), 429);

        Ads::hide($kind, $id, Auth::user(), $reason);
        $this->adSlots = array_map(fn ($s) => $s === "$kind:$id" ? false : $s, $this->adSlots);
        if ($kind === 'sponsorship' && $this->sponsorshipId === $id) {
            $this->sponsorshipId = null;
        }
        Flux::toast($reason ? 'Thanks. The ad is hidden and our sales team will review it.' : 'Ad hidden.');
    }

    /**
     * Context only: the stop being viewed, or the Near tab's stops. Never anything else about the reader.
     *
     * @return array<int, string>
     */
    protected function adStops(): array
    {
        return $this->stop ? [$this->stop] : ($this->tab === 'near' ? (Auth::user()?->stops ?: []) : []);
    }
}
