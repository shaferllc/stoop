<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Admin pages are for staff only. Checked on load and again on every action.
 */
trait StaffOnly
{
    public function bootStaffOnly(): void
    {
        abort_unless(Auth::user()?->is_staff, 403);
    }

    protected function staff(): User
    {
        return Auth::user();
    }
}
