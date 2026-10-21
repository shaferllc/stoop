<?php

namespace App\Livewire\Pages;

use App\Models\Place;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Places')]
class PlacesIndex extends Component
{
    #[Url(except: '')]
    public string $list = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $neighborhood = '';

    public function render(): View
    {
        $places = Place::query()
            ->when($this->list, fn ($q) => $q->where('list_name', $this->list))
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->when($this->neighborhood, fn ($q) => $q->where('neighborhood', $this->neighborhood))
            ->withCount(['dispatches' => fn ($q) => $q->where('status', 'approved')])
            ->orderByRaw('list_rank is null, list_rank')->orderBy('name')
            ->take(200)->get();

        return view('livewire.pages.places-index', [
            'places' => $places,
            'lists' => Place::whereNotNull('list_name')->distinct()->orderBy('list_name')->pluck('list_name'),
            'neighborhoods' => Place::whereNotNull('neighborhood')->distinct()->orderBy('neighborhood')->pluck('neighborhood'),
        ]);
    }
}
