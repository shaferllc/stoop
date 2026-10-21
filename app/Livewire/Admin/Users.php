<?php

namespace App\Livewire\Admin;

use App\Models\Penalty;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::stoop')]
#[Title('People · Admin')]
class Users extends Component
{
    use StaffOnly, WithPagination;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $penalized = Penalty::active()->whereIn('type', ['timeout', 'ban'])->pluck('user_id');

        $users = User::query()
            ->when(trim($this->q) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('username', 'like', '%'.ltrim(trim($this->q), '@').'%')
                ->orWhere('name', 'like', '%'.trim($this->q).'%')
                ->orWhere('email', 'like', '%'.trim($this->q).'%')))
            ->when($this->filter === 'staff', fn ($q) => $q->where('is_staff', true))
            ->when($this->filter === 'penalized', fn ($q) => $q->whereIn('id', $penalized))
            ->when($this->filter === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->when($this->filter === 'bots', fn ($q) => $q->where('is_bot', true))
            ->withCount('dispatches')
            ->latest()->paginate(50);

        return view('livewire.admin.users', ['users' => $users, 'penalized' => $penalized->all()]);
    }
}
