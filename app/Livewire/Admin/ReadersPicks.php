<?php

namespace App\Livewire\Admin;

use App\Support\ReadersPicks as Picks;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Been here check-ins on each of the magazine's lists, for editors picking readers' favorites.
 */
#[Layout('layouts::stoop')]
#[Title('Readers’ picks · Admin')]
class ReadersPicks extends Component
{
    use StaffOnly;

    #[Url]
    public string $list = '';

    #[Url]
    public int $days = 90;

    public function render(): View
    {
        $lists = Picks::lists();
        if (! $lists->contains($this->list)) {
            $this->list = (string) $lists->first();
        }
        $this->days = in_array($this->days, [30, 90, 365], true) ? $this->days : 90;

        return view('livewire.admin.readers-picks', [
            'lists' => $lists,
            'rows' => $this->list !== '' ? Picks::forList($this->list, $this->days) : collect(),
        ]);
    }
}
