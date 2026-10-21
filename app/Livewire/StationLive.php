<?php

namespace App\Livewire;

use App\Support\Metro;
use App\Support\Stations;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Live info on a station page: next trains, Bikeshare nearby, and local links. Loads after
 * the page so a slow outside service never holds it up, and refreshes every 30 seconds.
 */
#[Lazy]
class StationLive extends Component
{
    #[Locked]
    public string $code;

    public function placeholder(): string
    {
        return '<div class="mt-3 h-16 animate-pulse rounded-lg bg-chip motion-reduce:animate-none"></div>';
    }

    public function render(): View
    {
        return view('livewire.station-live', [
            'trains' => Metro::nextTrains($this->code),
            'bikes' => Metro::bikeshare($this->code),
            'inDc' => Stations::jurisdiction($this->code) === 'DC',
        ]);
    }
}
