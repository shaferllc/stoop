<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Support\LostAndFound;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Lost & found board: open posts first, filtered by stop, line and pet or item.
 */
#[Layout('layouts::stoop')]
#[Title('Lost & found')]
class LostFound extends Component
{
    use HandlesDispatchActions;

    #[Url]
    public ?string $stop = null;

    #[Url]
    public ?string $line = null;

    #[Url]
    public ?string $kind = null;

    #[Url(except: false)]
    public bool $reunited = false;

    public int $limit = 30;

    #[On('dispatch-filed')]
    public function refreshAfterPost(): void {}

    public function loadMore(): void
    {
        $this->limit += 30;
    }

    public function render(): View
    {
        $valid = validator(['stop' => $this->stop, 'line' => $this->line, 'kind' => $this->kind], [
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'kind' => ['nullable', Rule::in(array_keys(LostAndFound::KINDS))],
        ])->passes();
        if (! $valid) {
            $this->reset('stop', 'line', 'kind');
        }

        $dispatches = LostAndFound::board($this->stop, $this->line, $this->kind, $this->reunited, Auth::user())
            ->forCards()->latest()->take($this->limit)->get();

        return view('livewire.pages.lost-found', ['dispatches' => $dispatches, 'state' => $this->viewerState($dispatches)]);
    }
}
