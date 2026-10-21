<?php

namespace App\Console\Commands;

use App\Bots\GameDay;
use App\Support\Sports;
use Illuminate\Console\Command;

/**
 * Keeps DC games fresh from ESPN, then lets @gameday open today's threads and post finals.
 * Scheduled every five minutes by @gameday; it only calls ESPN hourly unless a game is live.
 */
class SyncSports extends Command
{
    protected $signature = 'stoop:sync-sports {--force : Import every team now, even if it ran within the hour}';

    protected $description = 'Import DC teams\' schedules and scores from ESPN and post game-day threads and finals';

    public function handle(GameDay $bot): void
    {
        $stored = Sports::sync((bool) $this->option('force'));
        $this->info($stored === null ? 'Nothing live; skipped ESPN.' : "Stored $stored games.");

        $threads = $bot->postThreads();
        $finals = $bot->postFinals();
        $this->info("Opened $threads game threads, posted $finals finals.");
    }
}
