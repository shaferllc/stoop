<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Livewire\Concerns\ShowsAds;
use App\Models\Dispatch;
use App\Models\Sponsorship;
use App\Models\User;
use App\Support\Ads;
use App\Support\Suggestions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Front Stoop')]
class Feed extends Component
{
    use HandlesDispatchActions, ShowsAds;

    #[Url(except: '')]
    public string $tab = '';

    #[Url]
    public ?string $line = null;

    #[Url]
    public ?string $stop = null;

    #[Url]
    public string $q = '';

    public int $limit = 20;

    /** The sponsored card for this page view, chosen once so refreshes don't recount it. */
    #[Locked]
    public ?int $sponsorshipId = null;

    /** Dispatches newer than this wait behind the "N new" button instead of shifting the feed. */
    public ?string $since = null;

    public function mount(): void
    {
        // With no tab in the URL, reopen the feed this person used last.
        if ($this->tab === '') {
            $this->tab = session('stoop.tab', 'rush');
        }

        if (! in_array($this->tab, ['rush', 'latest', 'following', 'near'], true)) {
            $this->tab = 'rush';
        }

        $this->since = now()->toDateTimeString();

        if ($sponsorship = Sponsorship::for($this->stop, $this->line, Ads::hidden('sponsorship'))) {
            Ads::record($sponsorship);
            $this->sponsorshipId = $sponsorship->id;
        }

        if (! Auth::check() && in_array($this->tab, ['following', 'near'], true)) {
            $this->tab = 'rush';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['following', 'near'], true) && ! $this->actor()) {
            return;
        }

        $this->tab = $tab;
        $this->limit = 20;
        $this->since = now()->toDateTimeString();
        session(['stoop.tab' => $tab]);
    }

    /**
     * A new dispatch went up somewhere; re-rendering refreshes the "N new" button.
     */
    #[On('echo:feed,.published')]
    public function somethingPublished(): void {}

    public function showNew(): void
    {
        $this->since = now()->toDateTimeString();
        unset($this->dispatches);
    }

    /**
     * How many dispatches arrived since the feed was loaded. Polled every 30 seconds.
     */
    #[Computed]
    public function newCount(): int
    {
        if (! in_array($this->tab, ['latest', 'following'], true)) {
            return 0;
        }

        // Only posts the button can actually reveal: newer than the feed, but not dated in the future.
        return $this->baseQuery()->where('dispatches.created_at', '>', $this->since)
            ->where('dispatches.created_at', '<=', now())->count();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function suggestedPeople(): Collection
    {
        $user = Auth::user();

        return $user ? Suggestions::for($user, 3) : collect();
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }

    #[On('dispatch-filed')]
    public function refreshAfterPost(): void
    {
        if ($this->tab === 'rush') {
            $this->tab = 'latest';
        }

        $this->since = now()->toDateTimeString();
    }

    /**
     * @return Collection<int, Dispatch>
     */
    #[Computed]
    public function dispatches(): Collection
    {
        $query = $this->baseQuery()->forCards()
            ->when($this->tab !== 'rush', fn ($q) => $q->where('dispatches.created_at', '<=', $this->since));

        if ($this->tab === 'rush') {
            // ponytail: scores the last week in PHP; move to a stored, periodically updated score past ~5k posts a week
            return $query->where('created_at', '>=', now()->subWeek())->get()
                ->sortByDesc(fn (Dispatch $d) => $d->rushScore())
                ->take($this->limit)->values();
        }

        return $query->latest()->latest('id')->take($this->limit)->get();
    }

    /**
     * @return Builder<Dispatch>
     */
    protected function baseQuery(): Builder
    {
        $user = Auth::user();

        return Dispatch::topLevel()->visibleTo($user)
            ->when($this->line, fn ($q) => $q->where('line', $this->line))
            ->when($this->stop, fn ($q) => $q->where('stop', $this->stop))
            ->when(mb_strlen(trim($this->q)) >= 2, fn ($q) => $q->where('body', 'like', '%'.trim($this->q).'%'))
            ->when($this->tab === 'following' && $user, fn ($q) => $q->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhereIn('user_id', $user->following()->select('users.id'))))
            ->when($this->tab === 'near' && $user, fn ($q) => $q->whereIn('stop', $user->stops ?: ['']));
    }

    public function render(): View
    {

        return view('livewire.pages.feed', [
            'sponsorship' => $this->sponsorshipId ? Sponsorship::find($this->sponsorshipId) : null,
            'state' => $this->viewerState($this->dispatches),
        ]);
    }
}
