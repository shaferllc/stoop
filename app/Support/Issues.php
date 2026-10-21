<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\Dispatch;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issue drops: an issue posts as one @magazine thread, the issue itself as the parent
 * dispatch and each story as a reply with its link card, plus a page at /issues/{slug}.
 */
class Issues
{
    public const MAX_STORIES = 30;

    /**
     * Stories from pasted URLs, one per line. Titles come from imported articles or the
     * archive when Stoop knows the URL.
     *
     * @return list<array{url: string, title: ?string}>
     */
    public static function storiesFrom(string $urls): array
    {
        return collect(preg_split('/\s+/', $urls))->map(fn ($u) => trim($u))
            ->filter(fn ($u) => preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL) && strlen($u) <= 400)
            ->unique()->take(self::MAX_STORIES)
            ->map(fn ($url) => ['url' => $url, 'title' => DB::table('articles')->where('url', $url)->value('title') ?? DB::table('archive_stories')->where('url', $url)->value('title')])
            ->values()->all();
    }

    /**
     * Post the issue thread. Publishes once: a second call returns the same thread.
     */
    public static function publish(Issue $issue): ?Dispatch
    {
        // Claim the issue first, so a double click can't post two threads.
        if (! Issue::whereKey($issue->id)->whereNull('published_at')->update(['published_at' => now()])) {
            return $issue->fresh()->dispatch;
        }

        $account = Magazine::account();
        // ponytail: the house account skips the 10-a-minute posting limit for an issue's stories
        $post = function (array $input) use ($account) {
            RateLimiter::clear('dispatch:'.$account->id);

            return FileDispatch::handle($account, $input, confirmed: true);
        };

        $count = count($issue->stories);
        try {
            $thread = $post([
                'body' => Str::limit('The '.$issue->name.' issue is out: '.$count.' '.str('story')->plural($count).'.', 200).' '.route('issues.show', $issue),
                'line' => 'blue',
            ]);
        } catch (Throwable $e) {
            Issue::whereKey($issue->id)->update(['published_at' => null]);

            throw $e;
        }
        $issue->forceFill(['dispatch_id' => $thread->id])->save();

        foreach ($issue->stories as $story) {
            $title = $story['title'] ? Str::limit($story['title'], config('stoop.max_length') - strlen($story['url']) - 2, '…').' ' : '';
            rescue(fn () => $post(['body' => $title.$story['url'], 'parentId' => $thread->id]), null, false);
        }

        return $thread;
    }
}
