<?php

namespace App\Livewire\Concerns;

use App\Actions\DispatchActions;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\StoopActivity;
use App\Support\QuoteRequests;
use App\Support\Translator;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Card actions shared by every page that lists dispatches.
 */
trait HandlesDispatchActions
{
    public ?int $reporting = null;

    public string $reportReason = 'hate';

    public string $reportNote = '';

    public ?int $undoId = null;

    /** @var array<int, string> Translated text by dispatch id, for this page view. */
    public array $translations = [];

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
        abort_unless(in_array($kind, ['save', 'been', 'going', 'mumbo'], true), 422);

        if (! $user = $this->actor()) {
            return;
        }

        $on = DispatchActions::toggleMark($user, $this->findVisible($id), $kind);

        if ($kind === 'save') {
            Flux::toast($on ? 'Saved.' : 'Removed from Saved.');
        }
    }

    public function vote(int $id, int $optionId): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        DispatchActions::vote($user, $this->findVisible($id), $optionId);
    }

    /**
     * Hand out one of today's half-smokes. Several can go to the same dispatch, never your own.
     */
    public function giveHalfSmoke(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = $this->findVisible($id);

        // The button only shows when half-smokes are on and the dispatch isn't yours.
        if ($user->half_smokes_on && $dispatch->user_id !== $user->id && $reason = DispatchActions::giveHalfSmoke($user, $dispatch)) {
            Flux::toast($reason);
        }
    }

    public function toggleFollow(int $userId): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $target = User::findOrFail($userId);

        if (DispatchActions::toggleFollow($user, $target)) {
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

        $target = User::findOrFail($userId);
        DispatchActions::silence($user, $target, $type);

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

        DispatchActions::report($user, $this->findVisible((int) $this->reporting), $this->reportReason, $this->reportNote);

        $this->reporting = null;
        Flux::toast('Reported and hidden for you. A moderator will review it.');
    }

    /**
     * Soft-delete, with 30 seconds to undo. Replies under it survive under a tombstone.
     */
    public function deleteDispatch(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = Dispatch::findOrFail($id);
        abort_unless($dispatch->user_id === $user->id || $user->is_staff, 403);

        $dispatch->delete();
        $this->undoId = $dispatch->id;
    }

    public function restoreDispatch(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = Dispatch::onlyTrashed()->findOrFail($id);
        abort_unless($dispatch->user_id === $user->id || $user->is_staff, 403);

        if ($dispatch->deleted_at->gt(now()->subSeconds(35))) {
            $dispatch->restore();
            Flux::toast('Restored.');
        }

        $this->undoId = null;
    }

    /**
     * Whoever asked an Ask DC question picks the reply that helped. The answerer gets Know-It-All points.
     */
    public function pickBestAnswer(int $replyId): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $reply = Dispatch::visibleTo($user)->whereNotNull('parent_id')->findOrFail($replyId);
        $question = $reply->parent;
        abort_unless($question && $question->type === 'ask' && $question->user_id === $user->id && $reply->user_id !== $user->id, 403);

        $unpick = $question->best_answer_id === $reply->id;
        $question->forceFill(['best_answer_id' => $unpick ? null : $reply->id])->save();

        if (! $unpick && $reply->user) {
            $reply->user->notify(new StoopActivity('best', $user, $reply));
        }

        Flux::toast($unpick ? 'Best Answer removed.' : 'Marked as the Best Answer. Thanks for closing the loop.');
    }

    /**
     * The author of a thread can hide replies under it. Moderators still see them.
     */
    public function toggleHideReply(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $reply = Dispatch::whereNotNull('parent_id')->findOrFail($id);
        abort_unless($reply->parent?->user_id === $user->id, 403);

        $reply->forceFill(['hidden_by_author' => ! $reply->hidden_by_author])->save();
    }

    public function togglePin(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $dispatch = $user->dispatches()->whereNull('parent_id')->where('status', 'approved')->findOrFail($id);
        $pinned = $user->pinned_dispatch_id === $dispatch->id;
        $user->forceFill(['pinned_dispatch_id' => $pinned ? null : $dispatch->id])->save();

        Flux::toast($pinned ? 'Unpinned.' : 'Pinned to your profile.');
    }

    public function translate(int $id): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        if (! RateLimiter::attempt('translate:'.$user->id, 10, fn () => true)) {
            Flux::toast('Too many translations. Try again in a minute.');

            return;
        }

        $text = Translator::translate($this->findVisible($id)->body, $user->translation_language ?? 'en');
        $text === null ? Flux::toast('Couldn’t translate that right now.') : $this->translations[$id] = $text;
    }

    public function showOriginal(int $id): void
    {
        unset($this->translations[$id]);
    }

    /**
     * Editors ask the author before quoting a dispatch in a story.
     */
    public function requestQuote(int $id): void
    {
        if ($user = $this->actor()) {
            Flux::toast(QuoteRequests::request($user, $this->findVisible($id)) ?? 'Asked. You’ll see the answer in Admin → Magazine.');
        }
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
