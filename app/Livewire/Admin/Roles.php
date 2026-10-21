<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\Roles as StaffRoles;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Roles · Admin')]
class Roles extends Component
{
    use StaffOnly;

    protected const ROLE = 'admin';

    /** Someone to give a role to, by username. */
    public string $username = '';

    /** @var array<int, string> */
    public array $roles = [];

    public function save(): void
    {
        $this->username = ltrim(trim($this->username), '@');
        $this->validate([
            'username' => ['required', 'exists:users,username'],
            'roles' => ['array'],
            'roles.*' => [Rule::in(array_keys(StaffRoles::ROLES))],
        ], ['username.exists' => 'No one has that username.']);

        StaffRoles::set(User::where('username', $this->username)->firstOrFail(), $this->roles, $this->staff());
        $this->reset('username', 'roles');
        Flux::toast('Roles saved.');
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        [$this->username, $this->roles] = [$user->username, $user->staffRoles()];
    }

    public function render(): View
    {
        return view('livewire.admin.roles', ['staff' => User::where('is_staff', true)->orderBy('username')->get()]);
    }
}
