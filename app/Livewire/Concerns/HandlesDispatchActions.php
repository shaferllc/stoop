<?php

namespace App\Livewire\Concerns;

use App\Models\Dispatch;
use App\Models\Report;
use App\Models\User;
use App\Notifications\StoopActivity;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Card actions shared by every page that lists dispatches.
 */
trait HandlesDispatchActions
{
    public ?int $reporting = null;

    public string $reportReason = 'hate';

    public string $reportNote = '';

    /**
     * The signed-in user, or a redirect to log in for guests.
     */
    protected function actor(): ?User
    {
        if (! Auth::check()) {
            $this->redirectRoute('login', navigate: true);

            return null;
        }

        return Auth::user();
    }

    protected function findVisible(int $id): Dispatch
    {
        return Dispatch::visibleTo(Auth::user())->findOrFail($id);
    }

    public function toggleMark(int $id, string $kind): void
    {
        abort_unless(in_array($kind, ['save', 'been', 'going'], true), 422);

        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = $this->findVisible($id);
        $query = DB::table('marks')->where(['user_id' => $user->id, 'dispatch_id' => $dispatch->id, 'kind' => $kind]);

        if ($query->exists()) {
            $query->delete();
        } else {
            $user->marked()->attach($dispatch->id, ['kind' => $kind]);
        }

        if ($kind === 'save') {
            Flux::toast($query->exists() ? 'Saved.' : 'Removed from Saved.');
        }
    }

    public function vote(int $id, int $optionId): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = $this->findVisible($id);
        $option = $dispatch->options()->findOrFail($optionId);

        DB::table('poll_votes')->insertOrIgnore([
            'dispatch_id' => $dispatch->id,
            'poll_option_id' => $option->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function toggleFollow(int $userId): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        abort_if($userId === $user->id || in_array($userId, $user->hiddenUserIds(), true), 422);

        $target = User::findOrFail($userId);
        $changes = $user->following()->toggle($target->id);

        if ($changes['attached']) {
            $target->notify(new StoopActivity('follow', $user));
            Flux::toast("Following @{$target->username}.");
        } else {
            Flux::toast("Unfollowed @{$target->username}.");
        }
    }

    public function silence(int $userId, string $type): void
    {
        abort_unless(in_array($type, ['mute', 'block'], true), 422);

        if (! $user = $this->actor()) {
            return;
        }

        abort_if($userId === $user->id, 422);

        $target = User::findOrFail($userId);
        $user->silenced()->attach($target->id, ['type' => $type]);

        if ($type === 'block') {
            $user->following()->detach($target->id);
            $user->followers()->detach($target->id);
        }

        Flux::toast($type === 'block' ? "Blocked @{$target->username}." : "Muted @{$target->username}. Their posts won’t show for you.");
    }

    public function startReport(int $id): void
    {
        if ($this->actor()) {
            $this->reporting = $id;
            $this->reportReason = 'hate';
            $this->reportNote = '';
        }
    }

    /**
     * Hide the dispatch for the reporter right away, and for everyone once enough
     * different people report it, until a moderator reviews it.
     */
    public function submitReport(): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $this->validate([
            'reportReason' => ['required', Rule::in(array_keys(config('stoop.report_reasons')))],
            'reportNote' => ['nullable', 'string', 'max:500'],
        ]);

        $dispatch = $this->findVisible((int) $this->reporting);
        abort_if($dispatch->user_id === $user->id, 422);

        Report::firstOrCreate(
            ['user_id' => $user->id, 'dispatch_id' => $dispatch->id],
            ['reason' => $this->reportReason, 'note' => $this->reportNote ?: null],
        );

        if ($dispatch->reports()->whereNull('resolved_at')->count() >= config('stoop.report_threshold')) {
            $dispatch->update(['status' => 'hidden']);
        }

        $this->reporting = null;
        Flux::toast('Reported and hidden for you. A moderator will review it.');
    }

    public function deleteDispatch(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = Dispatch::findOrFail($id);
        abort_unless($dispatch->user_id === $user->id || $user->is_staff, 403);

        $dispatch->delete();
        Flux::toast('Dispatch deleted.');
    }

    public function quote(int $id): void
    {
        if ($this->actor()) {
            $this->dispatch('quote-dispatch', id: $this->findVisible($id)->id);
        }
    }

    /**
     * The viewer's own marks and votes for a page of dispatches, so cards can show state.
     *
     * @param  Collection<int, Dispatch>  $dispatches
     * @return array{marks: array<int, array<int, string>>, votes: array<int, int>}
     */
    protected function viewerState(Collection $dispatches): array
    {
        $user = Auth::user();
        $ids = $dispatches->pluck('id')->merge($dispatches->pluck('quote_id'))->filter()->all();

        if (! $user || ! $ids) {
            return ['marks' => [], 'votes' => []];
        }

        return [
            'marks' => DB::table('marks')->where('user_id', $user->id)->whereIn('dispatch_id', $ids)->get()
                ->groupBy('dispatch_id')->map(fn ($rows) => $rows->pluck('kind')->all())->all(),
            'votes' => DB::table('poll_votes')->where('user_id', $user->id)->whereIn('dispatch_id', $ids)
                ->pluck('poll_option_id', 'dispatch_id')->all(),
        ];
    }
}
