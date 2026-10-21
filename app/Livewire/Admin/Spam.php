<?php

namespace App\Livewire\Admin;

use App\Models\Penalty;
use App\Models\SpamCluster;
use App\Models\User;
use App\Support\SpamRings;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Spam · Admin')]
class Spam extends Component
{
    use StaffOnly;

    protected const ROLE = 'moderator';

    #[Url(except: 'open')]
    public string $status = 'open';

    public int $hours = 24;

    public function detect(): void
    {
        abort_unless(RateLimiter::attempt('spam-detect:'.$this->staff()->id, 6, fn () => true), 429);
        Flux::toast(SpamRings::detect().' open clusters.');
    }

    public function act(int $id): void
    {
        $this->validate(['hours' => ['required', 'integer', Rule::in(array_keys(Penalty::TIMEOUTS))]]);
        $done = SpamRings::act(SpamCluster::findOrFail($id), $this->staff(), $this->hours);
        Flux::toast("Hid {$done['hidden']} posts and put {$done['timeouts']} accounts in a timeout.");
    }

    public function dismiss(int $id): void
    {
        SpamRings::dismiss(SpamCluster::findOrFail($id));
    }

    public function render(): View
    {
        $clusters = SpamCluster::when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))->latest('updated_at')->take(100)->get();

        return view('livewire.admin.spam', [
            'clusters' => $clusters,
            'people' => User::whereIn('id', $clusters->pluck('members')->flatten()->unique())->get()->keyBy('id'),
        ]);
    }
}
