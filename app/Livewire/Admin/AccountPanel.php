<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\AccountCheck;
use App\Support\SignupRisk;
use App\Support\SpamRings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The bot and spam box on a person's admin page: sign-up risk, spam clusters, and a check
 * that asks Claude (or the heuristics) whether the account is a bot. Staff only, and only a
 * suggestion: acting on it is up to the moderator.
 */
class AccountPanel extends Component
{
    use StaffOnly;

    protected const ROLE = 'moderator';

    #[Locked]
    public int $userId;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function check(): void
    {
        abort_unless(RateLimiter::attempt('account-check:'.$this->staff()->id, 10, fn () => true), 429, 'Too many checks. Try again in a minute.');
        $this->result = AccountCheck::run(User::findOrFail($this->userId));
    }

    public function render(): View
    {
        $user = User::findOrFail($this->userId);

        return view('livewire.admin.account-panel', [
            'user' => $user,
            'held' => SignupRisk::holds($user),
            'clusters' => SpamRings::for($user),
            'claude' => AccountCheck::enabled(),
        ]);
    }
}
