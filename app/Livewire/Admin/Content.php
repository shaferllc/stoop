<?php

namespace App\Livewire\Admin;

use App\Models\Dispatch;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Content · Admin')]
class Content extends Component
{
    use StaffOnly;

    public function render(): View
    {
        return view('livewire.admin.content', [
            'scheduled' => Dispatch::with('user')->where('status', 'scheduled')->orderBy('publish_at')->take(50)->get(),
            'callouts' => Dispatch::with('user')->where('type', 'callout')->where('status', 'approved')->withCount('replies')->latest()->take(20)->get(),
        ]);
    }
}
