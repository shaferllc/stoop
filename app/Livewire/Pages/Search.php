<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\SearchIndex;
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

        return SearchIndex::search(Dispatch::visibleTo(Auth::user())->forCards()->with('parent.user'), $q);
    }

    public function render(): View
    {
        $q = trim($this->q);
        $valid = mb_strlen($q) >= 2 && mb_strlen($q) <= 100;
        $viewer = Auth::user();

        $dispatches = $valid && $this->tab === 'dispatches' ? $this->dispatches($q) : collect();

        // Older magazine stories (not dispatches), matching every word in the title or excerpt.
        $magazine = $valid && $this->tab === 'dispatches' && ! $this->getErrorBag()->has('q')
            ? collect(preg_split('/\s+/', $q))->reduce(fn ($query, $word) => $query->where(fn ($w) => $w->where('title', 'like', '%'.$word.'%')->orWhere('description', 'like', '%'.$word.'%')), DB::table('archive_stories'))
                ->orderByDesc('published_on')->take(5)->get()
            : collect();

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
            'magazine' => $magazine,
            'people' => $people,
            'stations' => $stations,
            'state' => $this->viewerState($dispatches),
        ]);
    }
}
