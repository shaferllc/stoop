<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\StoopActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * When a magazine article changes after it was posted (the feed shows new content, or an
 * editor marks it corrected or updated), @magazine replies in its thread with the note and
 * everyone who replied, saved or reacted hears about it. Each update posts once.
 */
class ArticleUpdates
{
    /**
     * Post the update. $key identifies it (the feed's content hash, or a random key for an
     * editor's note); the same key never posts twice. Returns how many people were told,
     * or null when it was already posted or the article has no thread.
     */
    public static function post(object $article, string $note, string $key, ?User $editor = null): ?int
    {
        $thread = $article->dispatch_id ? Dispatch::where('status', 'approved')->find($article->dispatch_id) : null;
        if (! $thread) {
            return null;
        }

        try {
            $id = DB::table('article_updates')->insertGetId([
                'article_id' => $article->id, 'note' => Str::limit($note, 400, ''), 'key' => $key,
                'created_by' => $editor?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $account = Magazine::account();
        // ponytail: the house account skips the 10-a-minute posting limit for these
        RateLimiter::clear('dispatch:'.$account->id);
        $reply = rescue(fn () => FileDispatch::handle($account, [
            'body' => Str::limit('Update: '.$note, config('stoop.max_length'), '…'),
            'parentId' => $thread->id,
        ], confirmed: true), null, false);

        $notified = 0;
        if ($reply) {
            foreach (self::audience($thread, $account) as $user) {
                $user->notify(new StoopActivity('update', $account, $reply));
                $notified++;
            }
        }

        DB::table('article_updates')->where('id', $id)->update(['dispatch_id' => $reply?->id, 'notified' => $notified, 'updated_at' => now()]);

        return $notified;
    }

    /**
     * Everyone who replied to, saved or reacted to the thread, once each, minus bots and anyone
     * who muted or blocked the magazine.
     *
     * @return Collection<int, User>
     */
    public static function audience(Dispatch $thread, User $account): Collection
    {
        $ids = Dispatch::where('parent_id', $thread->id)->pluck('user_id')
            ->merge(DB::table('marks')->where('dispatch_id', $thread->id)->pluck('user_id'))
            ->merge(DB::table('half_smokes')->where('dispatch_id', $thread->id)->pluck('giver_id'))
            ->unique();

        return User::whereIn('id', $ids)->where('id', '!=', $account->id)->where('is_bot', false)->get()
            ->reject(fn (User $u) => in_array($account->id, $u->hiddenUserIds(), true))
            ->values();
    }
}
