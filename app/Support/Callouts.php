<?php

namespace App\Support;

use App\Models\Dispatch;
use Illuminate\Support\Collection;

/**
 * Newsroom callouts tied to stories. A callout can carry the story it's for (an article URL
 * or a working title, staff only), and people replying can tick "The newsroom may quote this
 * reply in a story." Only those replies can be copied out or exported.
 */
class Callouts
{
    /**
     * Replies the newsroom may quote: consented, public and not deleted.
     *
     * @return Collection<int, Dispatch>
     */
    public static function quotes(Dispatch $callout): Collection
    {
        return $callout->replies()->with('user')->where('status', 'approved')
            ->where('details->quote_ok', true)->oldest()->get();
    }

    /**
     * A CSV of the quotable replies.
     */
    public static function csv(Dispatch $callout): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['quote', 'username', 'name', 'posted_at', 'url']);
        foreach (self::quotes($callout) as $reply) {
            fputcsv($out, array_map(self::cell(...), [$reply->body, $reply->user->username, $reply->user->name, $reply->created_at->toDateTimeString(), route('dispatches.show', $reply)]));
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /**
     * Spreadsheet apps run cells starting with = + - @ as formulas; make them plain text.
     */
    protected static function cell(?string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'".$value : (string) $value;
    }
}
