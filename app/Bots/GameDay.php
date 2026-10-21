<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\Game;
use App\Support\Sports;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @gameday opens a pick thread on the morning of each DC game (Going Out line, at the venue's
 * stop), posts the final score in it, and answers "@gameday Caps" with the score or next game.
 */
class GameDay extends Bot
{
    // Never more than this many game threads in a day, however busy the calendar.
    public const DAILY_LIMIT = 6;

    public function username(): string
    {
        return 'gameday';
    }

    public function name(): string
    {
        return 'Game Day';
    }

    public function description(): string
    {
        return 'A pick thread on the morning of every DC game, and the final score after. Reply “@gameday Caps” for the score or the next game.';
    }

    public function schedule(Schedule $schedule): void
    {
        // Hourly from ESPN, every five minutes while a game is live (the command decides), threads from 8am.
        $schedule->command('stoop:sync-sports')->everyFiveMinutes()->withoutOverlapping();
    }

    /**
     * Today's pick threads, once per game. Returns how many were opened.
     */
    public function postThreads(): int
    {
        $now = now(config('stoop.timezone'));

        if ($now->hour < 8) {
            return 0;
        }

        $opened = Game::whereNotNull('team_key')->whereNotNull('dispatch_id')->where('starts_at', '>=', $now->copy()->startOfDay()->utc())->count();
        $games = Game::whereNotNull('external_id')->whereNull('dispatch_id')->where('status', 'pre')
            ->where(fn ($q) => $q->whereNull('status_detail')->orWhere('status_detail', '!=', 'TBA'))
            // Two DC teams playing each other (Hoyas vs. Terps) get one thread, not two.
            ->whereNotIn('external_id', Game::whereNotNull('dispatch_id')->whereNotNull('external_id')->select('external_id'))
            ->whereBetween('starts_at', [now(), $now->copy()->endOfDay()->utc()])
            ->orderBy('starts_at')->take(max(0, self::DAILY_LIMIT - $opened))->get();

        return $games->filter(fn (Game $game) => $this->openThread($game))->count();
    }

    public function openThread(Game $game): bool
    {
        if ($game->external_id && Game::where('external_id', $game->external_id)->whereNotNull('dispatch_id')->exists()) {
            return false;
        }

        try {
            $team = $game->teamConfig();
            $dispatch = $this->post([
                'type' => 'poll', 'line' => 'orange', 'stop' => $game->stop,
                'options' => [$game->team, $game->opponent],
                'body' => 'Game day: '.$game->matchup().', '.$game->starts_at->timezone(config('stoop.timezone'))->format('g:ia')
                    .($game->venue ? ' at '.$game->venue : '').'. Who wins? Right picks are worth 10 XP. Score, trains and watch parties: '.route('sports.game', $game),
            ]);
            // Picks close at first pitch, not 24 hours from now.
            $dispatch->forceFill(['closes_at' => $game->starts_at, 'details' => ['game' => $game->id]])->save();
            $options = $dispatch->options()->pluck('id', 'label');

            return (bool) Game::whereKey($game->id)->whereNull('dispatch_id')->update([
                'dispatch_id' => $dispatch->id,
                'team_option_id' => $options[$game->team] ?? null,
                'opponent_option_id' => $options[$game->opponent] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('@gameday could not open a thread', ['game' => $game->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The final score in each finished game's thread, once. Returns how many posted.
     */
    public function postFinals(): int
    {
        $posted = 0;

        foreach (Game::where('status', 'final')->whereNotNull('dispatch_id')->whereNull('final_posted_at')->get() as $game) {
            // Claim it first, so a crash can't post it twice.
            if (! Game::whereKey($game->id)->whereNull('final_posted_at')->update(['final_posted_at' => now()])) {
                continue;
            }
            try {
                $this->post(['parentId' => $game->dispatch_id, 'body' => 'Final: '.$game->score().'. '.match ($game->winner) {
                    'team' => $game->team.' win. Right picks just earned 10 XP.',
                    'opponent' => $game->opponent.' win. Right picks just earned 10 XP.',
                    default => 'A draw, so nobody scores on this one.',
                }]);
                $posted++;
            } catch (Throwable $e) {
                Log::warning('@gameday could not post a final', ['game' => $game->id, 'error' => $e->getMessage()]);
            }
        }

        return $posted;
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $asked = trim((string) preg_replace('/^.*?@gameday\b/is', '', $dispatch->body));
        $key = $asked === '' ? null : Sports::findTeam($asked);

        if ($asked !== '' && ! $key) {
            return 'I don’t know that team. Try “@gameday Caps”, Nats, Wizards, Mystics, Commanders, United, Spirit, Hoyas or Terps.';
        }

        $games = Game::whereNotNull('team_key')->when($key, fn ($q) => $q->where('team_key', $key));
        $live = (clone $games)->where('status', 'live')->orderBy('starts_at')->get();

        if ($live->isNotEmpty()) {
            return 'Live: '.$live->map(fn (Game $g) => $g->score().($g->status_detail ? ' ('.$g->status_detail.')' : ''))->join('; ').'.';
        }

        $next = (clone $games)->where('status', 'pre')->where('starts_at', '>', now())->orderBy('starts_at')->first();
        $last = $key ? (clone $games)->where('status', 'final')->latest('starts_at')->first() : null;

        $parts = array_filter([
            $last ? 'Last: '.$last->score().' ('.$last->starts_at->timezone(config('stoop.timezone'))->format('M j').').' : null,
            $next ? 'Next: '.$next->matchup().', '.$next->when().($next->venue ? ' at '.$next->venue : '').'.' : null,
        ]);

        return $parts ? implode(' ', $parts) : 'No '.($key ? Sports::teams()[$key]['name'] : 'DC').' games on the schedule right now.';
    }
}
