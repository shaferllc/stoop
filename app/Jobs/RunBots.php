<?php

namespace App\Jobs;

use App\Actions\FileDispatch;
use App\Bots\Bot;
use App\Models\Dispatch;
use App\Support\Bots;
use App\Support\Magazine;
use App\Support\Mentions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lets the house accounts answer a newly public dispatch, at most once each.
 */
class RunBots implements ShouldQueue
{
    use Queueable;

    public function __construct(public Dispatch $dispatch) {}

    public function handle(): void
    {
        $dispatch = $this->dispatch->fresh('user');

        if (! $dispatch || $dispatch->status !== 'approved' || $dispatch->user->is_bot) {
            return;
        }

        if (Bots::isGreeting($dispatch->body)) {
            $this->reply($dispatch, 'dc', 'Welcome to the neighborhood, '.$dispatch->user->name.'.');
        }

        if (in_array('stoop', Mentions::usernames($dispatch->body), true)) {
            $this->reply($dispatch, 'stoop', Bots::answer($dispatch->body));
        }

        // A bot that's mentioned always answers, with its how-to if it has nothing else to say.
        $mentioned = Mentions::usernames($dispatch->body);

        // The magazine's desk account isn't a bot, but people mention it: it shares its latest stories.
        $magazine = config('stoop.magazine_account');
        if (in_array($magazine, $mentioned, true) && $dispatch->user->username !== $magazine) {
            $articles = DB::table('articles')->orderByDesc('published_at')->orderByDesc('id')->take(3)->get(['title', 'url', 'dispatch_id']);
            if ($articles->isNotEmpty()) {
                Magazine::account();
                $this->reply($dispatch, $magazine, 'Latest from the magazine:'.$articles->map(fn ($a, $i) => "\n".($i + 1).'. '.Str::limit($a->title, 90)
                    .' '.($a->dispatch_id ? route('dispatches.show', $a->dispatch_id) : $a->url))->join(''));
            }
        }

        foreach (Bot::all() as $bot) {
            $body = $bot->wantsToReply($dispatch) ? $bot->reply($dispatch) : null;
            if (! $body && in_array($bot->username(), $mentioned, true)) {
                $body = $bot->help();
            }
            if ($body) {
                $this->reply($dispatch, $bot->username(), $body);
            }
        }
    }

    protected function reply(Dispatch $dispatch, string $bot, string $body): void
    {
        $account = Bots::account($bot);

        if ($dispatch->replies()->where('user_id', $account->id)->exists()) {
            return;
        }

        FileDispatch::handle($account, ['parentId' => $dispatch->id, 'body' => $body], confirmed: true);
    }
}
