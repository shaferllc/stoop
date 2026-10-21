<?php

namespace App\Livewire\Pages;

use App\Models\Dispatch;
use App\Support\Tips;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::stoop')]
#[Title('Tip the newsroom')]
class TipCreate extends Component
{
    use WithFileUploads;

    // "Tip the newsroom about this" from a dispatch's menu.
    #[Url, Locked]
    public ?int $dispatch = null;

    public string $body = '';

    public string $link = '';

    public bool $contactOk = false;

    /** @var array<int, mixed> */
    public array $photos = [];

    public bool $sent = false;

    public function submit(): void
    {
        $this->resetErrorBag();

        Tips::submit(Auth::user(), [
            'body' => $this->body,
            'link' => $this->link,
            'dispatchId' => $this->dispatch,
            'contactOk' => $this->contactOk,
            'photos' => $this->photos,
        ]);

        $this->reset('body', 'link', 'contactOk', 'photos');
        $this->sent = true;
        Flux::toast('Sent to the newsroom. Thank you.');
    }

    public function render(): View
    {
        return view('livewire.pages.tip-create', [
            'about' => $this->dispatch ? Dispatch::with('user')->visibleTo(Auth::user())->find($this->dispatch) : null,
        ]);
    }
}
