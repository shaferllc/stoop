<?php

namespace App\Livewire\Admin;

use App\Models\Tip;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tips as TipInbox;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The editors' tip inbox. Tips are private to staff.
 */
#[Layout('layouts::stoop')]
#[Title('Tips · Admin')]
class Tips extends Component
{
    use StaffOnly, WithPagination;

    public const ROLE = 'editor';

    #[Url(except: 'new')]
    public string $status = 'new';

    /** @var array<int, string> */
    public array $replies = [];

    public function setStatus(int $id, string $status): void
    {
        abort_unless(isset(Tip::STATUSES[$status]), 422);
        $tip = Tip::findOrFail($id);
        $tip->update(['status' => $status]);
        Audit::log('tip.status', $tip, ['status' => $status]);
    }

    public function assign(int $id, ?int $userId): void
    {
        abort_if($userId && ! self::editors()->contains('id', $userId), 422);
        $tip = Tip::findOrFail($id);
        $tip->update(['assigned_to' => $userId ?: null]);
        Audit::log('tip.assigned', $tip, ['to' => $userId]);
    }

    public function reply(int $id): void
    {
        $tip = Tip::with('user')->findOrFail($id);

        try {
            TipInbox::reply($tip, $this->staff(), (string) ($this->replies[$id] ?? ''));
        } catch (ValidationException $e) {
            Flux::toast(collect($e->errors())->flatten()->first(), variant: 'danger');

            return;
        }

        unset($this->replies[$id]);
        if ($tip->status === 'new') {
            $tip->update(['status' => 'following']);
        }
        Flux::toast('Sent to @'.$tip->user->username.' in Stoop messages.');
    }

    /**
     * Staff people who can take a tip (not the house accounts).
     */
    public static function editors(): Collection
    {
        return User::where('is_staff', true)->where('is_bot', false)->where('username', '!=', config('stoop.magazine_account'))->orderBy('username')->get(['id', 'username']);
    }

    public function render(): View
    {
        return view('livewire.admin.tips', [
            'tips' => Tip::with(['user', 'assignee', 'dispatch'])
                ->when(! in_array($this->status, ['all', 'mine'], true), fn ($q) => $q->where('status', $this->status))
                ->when($this->status === 'mine', fn ($q) => $q->where('assigned_to', $this->staff()->id))
                ->latest()->paginate(25),
            'counts' => Tip::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'editors' => self::editors(),
        ]);
    }

    public function updatingStatus(string $value): void
    {
        abort_unless(isset(Tip::STATUSES[$value]) || in_array($value, ['all', 'mine'], true), 422);
        $this->resetPage();
    }
}
