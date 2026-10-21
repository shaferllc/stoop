<?php

namespace App\Bots;

use App\Models\Dispatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Validation\ValidationException;

/**
 * When pile-on detection flags a thread (see FileDispatch::checkPileOn), @calm posts one
 * neutral line as replies reopen. It never locks, hides, flags or reports anything.
 */
class Calm extends Bot
{
    public const LINES = [
        'Let’s keep it neighborly. Disagree with ideas, not people.',
        'Lots of voices here. Say your piece about the idea, not the person.',
        'Good to see people care. Keep it about the topic and keep it kind.',
    ];

    public function username(): string
    {
        return 'calm';
    }

    public function name(): string
    {
        return 'Calm';
    }

    public function description(): string
    {
        return 'Posts one friendly reminder on threads that suddenly get a flood of replies.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->run())->name('bot:calm')->everyTenMinutes()->withoutOverlapping();
    }

    /**
     * Threads flagged in the last day whose reply pause has ended get the reminder, once.
     * The pause blocks everyone but the author, so @calm speaks when replies reopen.
     */
    public function run(): int
    {
        $bot = $this->account();
        $posted = 0;

        $threads = Dispatch::where('status', 'approved')->where('flagged_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->whereNull('replies_locked_until')->orWhere('replies_locked_until', '<=', now()))
            ->whereDoesntHave('replies', fn ($q) => $q->withTrashed()->where('user_id', $bot->id))
            ->take(10)->get();

        foreach ($threads as $thread) {
            try {
                $this->post(['parentId' => $thread->id, 'body' => self::LINES[$thread->id % count(self::LINES)]]);
                $posted++;
            } catch (ValidationException) {
                // Replies limited to people the author follows: stay out of it.
            }
        }

        return $posted;
    }
}
