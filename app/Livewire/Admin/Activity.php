<?php

namespace App\Livewire\Admin;

use App\Support\Activity as Feed;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Activity · Admin')]
class Activity extends Component
{
    use StaffOnly;

    public function render(): View
    {
        return view('livewire.admin.activity', ['feed' => Feed::feed(), 'days' => Feed::days()]);
    }
}
