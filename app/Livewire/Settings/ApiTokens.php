<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Terminal & API')]
class ApiTokens extends Component
{
    public string $name = '';

    /** Shown once, right after the token is created. */
    public ?string $plainToken = null;

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:60']]);
        abort_if(Auth::user()->tokens()->count() >= 10, 422, 'You can have at most 10 tokens.');

        $this->plainToken = Auth::user()->createToken($this->name, ['dispatch:write'])->plainTextToken;
        $this->reset('name');
    }

    public function revoke(int $id): void
    {
        Auth::user()->tokens()->whereKey($id)->delete();
    }

    public function render(): View
    {
        return view('livewire.settings.api-tokens', ['tokens' => Auth::user()->tokens()->latest()->get()]);
    }
}
