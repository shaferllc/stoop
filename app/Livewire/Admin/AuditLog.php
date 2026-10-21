<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::stoop')]
#[Title('Audit log · Admin')]
class AuditLog extends Component
{
    use StaffOnly, WithPagination;

    #[Url(except: '')]
    public string $action = '';

    public function render(): View
    {
        return view('livewire.admin.audit-log', [
            'entries' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
                ->when($this->action, fn ($q) => $q->where('action', 'like', $this->action.'%'))
                ->latest('audit_logs.created_at')->latest('audit_logs.id')
                ->select('audit_logs.*', 'users.username as actor')->paginate(100),
            'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
