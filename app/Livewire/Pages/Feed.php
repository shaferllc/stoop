<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Front Stoop')]
class Feed extends Component
{
    use HandlesDispatchActions;

    #[Url]
    public string $tab = 'rush';

    #[Url]
    public ?string $line = null;

    #[Url]
    public ?string $stop = null;

    #[Url]
    public string $q = '';

    public int $limit = 20;

    public function mount(): void
    {
        if (! in_array($this->tab, ['rush', 'latest', 'following', 'near'], true)) {
            $this->tab = 'rush';
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
    }

    /**
     * @return Collection<int, Dispatch>
     */
    #[Computed]
    public function dispatches(): Collection
    {
        $user = Auth::user();

        $query = Dispatch::topLevel()->visibleTo($user)->forCards()
            ->when($this->line, fn ($q) => $q->where('line', $this->line))
            ->when($this->stop, fn ($q) => $q->where('stop', $this->stop))
            ->when(mb_strlen(trim($this->q)) >= 2, fn ($q) => $q->where('body', 'like', '%'.trim($this->q).'%'))
            ->when($this->tab === 'following' && $user, fn ($q) => $q->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhereIn('user_id', $user->following()->select('users.id'))))
            ->when($this->tab === 'near' && $user, fn ($q) => $q->whereIn('stop', $user->stops ?: ['']));

        if ($this->tab === 'rush') {
            // ponytail: scores the last week in PHP; move to a stored, periodically updated score past ~5k posts a week
            return $query->where('created_at', '>=', now()->subWeek())->get()
                ->sortByDesc(fn (Dispatch $d) => $d->rushScore())
                ->take($this->limit)->values();
        }

        return $query->latest()->latest('id')->take($this->limit)->get();
    }

    public function render(): View
    {
        return view('livewire.pages.feed', [
            'state' => $this->viewerState($this->dispatches),
        ]);
    }
}
