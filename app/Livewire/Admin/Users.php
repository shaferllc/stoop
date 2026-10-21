<?php

namespace App\Livewire\Admin;

use App\Actions\BulkPeople;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Strikes;
use Flux\Flux;
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

    protected const ROLE = 'moderator';

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    /** @var array<int, string> People ticked for a bulk action. */
    public array $selected = [];

    public string $bulkHours = '24';

    public string $bulkReason = '';

    public string $bulkNote = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'filter'], true)) {
            $this->resetPage();
        }
    }

    public function bulk(string $action): void
    {
        $data = ['ids' => array_map('intval', $this->selected), 'action' => $action, 'hours' => (int) $this->bulkHours, 'reason' => $this->bulkReason ?: config('stoop.rule_reasons.0'), 'body' => $this->bulkNote];
        validator($data, BulkPeople::rules())->validate();

        $count = BulkPeople::run($data['ids'], $action, $data, $this->staff());
        $this->reset('selected', 'bulkNote');
        Flux::toast(match ($action) {
            'verify' => "Verified $count.", 'timeout' => "Put $count in a timeout.", default => "Added a note to $count."
        });
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
            ->when($this->filter === 'ban_review', fn ($q) => $q->whereIn('id', Strikes::banCandidates()->pluck('id')))
            ->withCount('dispatches')
            ->latest()->paginate(50);

        return view('livewire.admin.users', ['users' => $users, 'penalized' => $penalized->all()]);
    }
}
