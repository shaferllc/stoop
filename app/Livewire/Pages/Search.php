<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Search')]
class Search extends Component
{
    use HandlesDispatchActions;

    #[Url]
    public string $q = '';

    #[Url(except: 'dispatches')]
    public string $tab = 'dispatches';

    /**
     * Dispatches and replies matching every word, with stemming ("restaurants" finds "restaurant").
     *
     * @return Collection<int, Dispatch>
     */
    protected function dispatches(string $q): Collection
    {
        $key = 'search:'.(Auth::id() ?? request()->ip());
        if (! RateLimiter::attempt($key, 15, fn () => true)) {
            $this->addError('q', 'That’s a lot of searching. Try again in a minute.');

            return collect();
        }

        $query = Dispatch::visibleTo(Auth::user())->forCards()->with('parent.user');

        if (DB::getDriverName() === 'sqlite') {
            // Quote each word so punctuation can't change the FTS query; prefix-match the last one.
            $terms = collect(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q)))->filter()->take(8);
            if ($terms->isEmpty()) {
                return collect();
            }
            $match = $terms->map(fn ($t, $i) => '"'.$t.'"'.($i === $terms->keys()->last() ? '*' : ''))->join(' ');
            $ids = DB::select('SELECT rowid FROM dispatches_fts WHERE dispatches_fts MATCH ? ORDER BY rank LIMIT 200', [$match]);
            $order = array_flip(array_column($ids, 'rowid'));

            // ponytail: keyword search with stemming; add embeddings for true search-by-meaning if people ask for it
            return $query->whereIn('dispatches.id', array_keys($order))->get()->sortBy(fn ($d) => $order[$d->id])->take(50)->values();
        }

        return $query->where('body', 'like', '%'.$q.'%')->latest()->take(50)->get();
    }

    public function render(): View
    {
        $q = trim($this->q);
        $valid = mb_strlen($q) >= 2 && mb_strlen($q) <= 100;
        $viewer = Auth::user();

        $dispatches = $valid && $this->tab === 'dispatches' ? $this->dispatches($q) : collect();

        $people = $valid && $this->tab === 'people'
            ? User::where('is_bot', false)
                ->whereNotIn('id', $viewer?->hiddenUserIds() ?? [])
                ->where(fn ($w) => $w->where('username', 'like', ltrim($q, '@').'%')->orWhere('name', 'like', '%'.$q.'%'))
                ->withCount('followers')->orderByDesc('followers_count')->take(30)->get()
            : collect();

        $stations = $valid && $this->tab === 'stations'
            ? collect(config('stoop.stations'))->filter(fn ($name, $code) => str_contains(strtolower($name.' '.$code), strtolower($q)))
            : collect();

        return view('livewire.pages.search', [
            'valid' => $valid,
            'dispatches' => $dispatches,
            'people' => $people,
            'stations' => $stations,
            'state' => $this->viewerState($dispatches),
        ]);
    }
}
