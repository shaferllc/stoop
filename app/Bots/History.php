<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\ClaudeText;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @history posts "On this day in DC" at 9am from the staff-edited list at /admin/history.
 */
class History extends Bot
{
    public const PREFIX = 'On this day in DC';

    public function username(): string
    {
        return 'history';
    }

    public function name(): string
    {
        return 'On This Day';
    }

    public function description(): string
    {
        return 'On this day in DC: a bit of city history at 9am, on days the editors have one.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postToday())->name('bot:history')
            ->dailyAt('9:00')->timezone(config('stoop.timezone'));
    }

    /**
     * Today's checked entries, once a day. Entries still marked "verify" never post.
     */
    public function postToday(): ?Dispatch
    {
        $today = CarbonImmutable::now(config('stoop.timezone'));
        $entries = DB::table('history_entries')->where(['month' => $today->month, 'day' => $today->day, 'verify' => false])->orderBy('id')->get();

        if ($entries->isEmpty() || $this->account()->dispatches()->withTrashed()->where('created_at', '>=', $today->startOfDay()->utc())->exists()) {
            return null;
        }

        $body = self::PREFIX.', '.$today->format('F j').':';
        foreach ($entries as $entry) {
            $next = "\n\n".$entry->text.($entry->link ? ' '.$entry->link : '');
            if (mb_strlen($body.$next) <= config('stoop.max_length')) {
                $body .= $next;
            }
        }

        $first = $entries->first();

        return $this->post(['line' => $first->line ?: 'blue', 'stop' => $first->stop, 'body' => $body]);
    }

    /**
     * A draft entry for editors, from Claude. Null when there's no key or no answer.
     */
    public static function draft(int $month, int $day, string $hint = ''): ?string
    {
        $date = CarbonImmutable::create(2000, $month, $day)->format('F j');
        $text = ClaudeText::ask(
            'You draft “On this day in DC” entries for Stoop, a Washington, DC social network. Write one well-documented, verifiable event in Washington, DC history that happened on the given date, as “YYYY: what happened.” in one or two plain sentences, under 300 characters. Prefer local DC life (neighborhoods, music, Metro, sports, home rule) over federal politics. Reply with only the entry. An editor will fact-check it.',
            "Date: $date".($hint !== '' ? "\nTopic hint: $hint" : ''),
            512,
        );

        return $text === null ? null : Str::limit($text, 400, '');
    }
}
