<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Admin pages are for staff only. Checked on load and again on every action. A page
 * that sets a ROLE constant also needs that staff role (see App\Support\Roles).
 */
trait StaffOnly
{
    public function bootStaffOnly(): void
    {
        abort_unless(Auth::user()?->hasRole(defined(static::class.'::ROLE') ? static::ROLE : null), 403);
    }

    protected function staff(): User
    {
        return Auth::user();
    }
}
