<?php

namespace App\Actions;

use App\Models\Dispatch;
use App\Models\HalfSmoke;
use App\Models\Report;
use App\Models\User;
use App\Notifications\StoopActivity;
use App\Support\Challenges;
use Illuminate\Support\Facades\DB;

/**
 * Card actions (marks, votes, half-smokes, follows, mutes, blocks, reports). The website's
 * dispatch cards and the app API both go through here, so the rules are the same everywhere.
 */
class DispatchActions
{
    /**
     * Toggle a save, Been here, Going, Interested or mumbo mark. Returns whether it's on now.
     * Going and Interested are one RSVP, so picking one drops the other.
     */
    public static function toggleMark(User $user, Dispatch $dispatch, string $kind): bool
    {
        abort_unless(in_array($kind, ['save', 'been', 'going', 'interested', 'mumbo', 'rally'], true), 422);
        // Rally is the game-day cheer, only on game threads.
        abort_if($kind === 'rally' && empty($dispatch->details['game']), 422);

        $query = DB::table('marks')->where(['user_id' => $user->id, 'dispatch_id' => $dispatch->id, 'kind' => $kind]);

        if ($query->exists()) {
            $query->delete();

            return false;
        }

        if (in_array($kind, ['going', 'interested'], true)) {
            DB::table('marks')->where(['user_id' => $user->id, 'dispatch_id' => $dispatch->id, 'kind' => $kind === 'going' ? 'interested' : 'going'])->delete();
        }

        $user->marked()->attach($dispatch->id, ['kind' => $kind]);

        if ($kind === 'been' && $dispatch->place_id) {
            Challenges::check($user);
        }

        return true;
    }

    /**
     * One vote per person on an open poll that isn't theirs. Votes can't be changed.
     */
    public static function vote(User $user, Dispatch $dispatch, int $optionId): void
    {
        $option = $dispatch->options()->findOrFail($optionId);

        if ($dispatch->isClosed() || $dispatch->user_id === $user->id) {
            return;
        }

        DB::table('poll_votes')->insertOrIgnore([
            'dispatch_id' => $dispatch->id,
            'poll_option_id' => $option->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Hand out one of today's half-smokes. Several can go to the same dispatch, never your own.
     * Returns why it didn't go out, or null when it did.
     */
    public static function giveHalfSmoke(User $user, Dispatch $dispatch): ?string
    {
        if (! $user->half_smokes_on) {
            return 'Turn on half-smokes in Settings first.';
        }

        if ($dispatch->user_id === $user->id) {
            return 'You can’t give yourself a half-smoke.';
        }

        if ($user->halfSmokesLeft() === 0) {
            return 'You’re out of half-smokes for today. More at midnight.';
        }

        HalfSmoke::create(['giver_id' => $user->id, 'dispatch_id' => $dispatch->id]);

        return null;
    }

    /**
     * Follow or unfollow. Returns whether the user follows the target now.
     */
    public static function toggleFollow(User $user, User $target): bool
    {
        abort_if($target->id === $user->id || in_array($target->id, $user->hiddenUserIds(), true), 422);

        $changes = $user->following()->toggle($target->id);

        if ($changes['attached']) {
            $target->notify(new StoopActivity('follow', $user));

            return true;
        }

        return false;
    }

    /**
     * Mute or block someone. Blocking also drops follows both ways.
     */
    public static function silence(User $user, User $target, string $type): void
    {
        abort_unless(in_array($type, ['mute', 'block'], true), 422);
        abort_if($target->id === $user->id, 422);

        if (! $user->silenced()->wherePivot('type', $type)->whereKey($target->id)->exists()) {
            $user->silenced()->attach($target->id, ['type' => $type]);
        }

        if ($type === 'block') {
            $user->following()->detach($target->id);
            $user->followers()->detach($target->id);
        }
    }

    /**
     * Hide the dispatch for the reporter right away, and for everyone once enough
     * different people report it, until a moderator reviews it.
     */
    public static function report(User $user, Dispatch $dispatch, string $reason, ?string $note): void
    {
        abort_if($dispatch->user_id === $user->id, 422);

        Report::firstOrCreate(
            ['user_id' => $user->id, 'dispatch_id' => $dispatch->id],
            ['reason' => $reason, 'note' => $note ?: null],
        );

        if ($dispatch->reports()->whereNull('resolved_at')->count() >= config('stoop.report_threshold')) {
            $dispatch->update(['status' => 'hidden']);
        }
    }
}
