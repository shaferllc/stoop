<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\CityEvent;
use App\Support\Happenings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Happenings by day, for the week ahead or a month, with city events alongside.
 */
#[Layout('layouts::stoop')]
#[Title('Happenings')]
class HappeningsCalendar extends Component
{
    use HandlesDispatchActions;

    #[Url(except: 'week')]
    public string $view = 'week';

    #[Url(except: 0)]
    public int $offset = 0;

    #[Url]
    public ?string $line = null;

    #[Url]
    public ?string $stop = null;

    /** Shows the personal feed link (the token itself is only read in render). */
    public bool $showFeed = false;

    public function mount(): void
    {
        $this->validateFilters();
    }

    public function updated(): void
    {
        $this->validateFilters();
    }

    protected function validateFilters(): void
    {
        $this->view = $this->view === 'month' ? 'month' : 'week';
        $this->offset = max(-12, min(12, $this->offset));
        validator(['line' => $this->line, 'stop' => $this->stop], [
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
        ])->fails() && $this->reset('line', 'stop');
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->offset = 0;
        $this->validateFilters();
    }

    public function resetFeed(): void
    {
        if ($user = $this->actor()) {
            Happenings::feedToken($user, reset: true);
            $this->showFeed = true;
            Flux::toast('New calendar link made. The old one stops working.');
        }
    }

    public function render(): View
    {
        [$from, $to] = Happenings::range($this->view, $this->offset);
        $tz = config('stoop.timezone');
        $user = Auth::user();

        $happenings = Happenings::between($from->utc(), $to->utc(), $user, $this->line, $this->stop)->forCards()->get();
        $events = $this->line ? collect() : CityEvent::where('starts_at', '<=', $to->utc())->where('ends_at', '>=', $from->utc())
            ->when($this->stop, fn ($q) => $q->where('stop', $this->stop))->orderBy('starts_at')->get();

        // Every day in range with something on, in DC time. Events running several days show on each.
        $byDay = $happenings->groupBy(fn ($d) => $d->happening->starts_at->timezone($tz)->toDateString());
        $days = collect();
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $on = $events->filter(fn ($e) => $e->starts_at->lte($day->endOfDay()) && $e->ends_at->gte($day));
            if (isset($byDay[$day->toDateString()]) || $on->isNotEmpty()) {
                $days->push(['day' => $day, 'happenings' => $byDay[$day->toDateString()] ?? collect(), 'events' => $on]);
            }
        }

        return view('livewire.pages.happenings', [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'state' => $this->viewerState($happenings),
            'feedUrl' => $user && $this->showFeed ? route('calendar.feed', Happenings::feedToken($user)) : null,
        ]);
    }
}
