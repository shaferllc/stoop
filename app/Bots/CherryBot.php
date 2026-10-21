<?php

namespace App\Bots;

use App\Models\Contest;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @cherry follows the National Park Service's Bloom Watch during cherry blossom season
 * (March 1 to April 15) and posts each new bloom stage at the Tidal Basin. Quiet the rest
 * of the year.
 */
class CherryBot extends Bot
{
    public const PAGE = 'https://www.nps.gov/subjects/cherryblossom/bloom-watch.htm';

    // NPS's six stages, in the order of the columns on its Bloom Watch table.
    public const STAGES = ['green buds', 'florets visible', 'extension of florets', 'peduncle elongation', 'puffy white', 'peak bloom'];

    public function username(): string
    {
        return 'cherry';
    }

    public function name(): string
    {
        return 'Bloom Watch';
    }

    public function description(): string
    {
        return 'Cherry blossom stages at the Tidal Basin from the National Park Service, March 1 to April 15.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->checkBloom())->hourlyAt(5)->between('7:00', '20:00')->timezone(config('stoop.timezone'))->name('bot:cherry');
    }

    public static function inSeason(): bool
    {
        $today = now(config('stoop.timezone'));

        return $today->month === 3 || ($today->month === 4 && $today->day <= 15);
    }

    /**
     * Posts the newest bloom stage NPS has recorded this year, once.
     */
    public function checkBloom(): ?Dispatch
    {
        if (! self::inSeason()) {
            return null;
        }

        try {
            $html = Cache::remember('cherry:page', now()->addHour(), fn () => Http::timeout(8)->get(self::PAGE)->throw()->body());
        } catch (Throwable) {
            return null;
        }

        $year = now(config('stoop.timezone'))->year;
        $dates = self::stages($html, $year);
        $stage = count($dates);

        // Only a stage past the last one posted: no backlog when starting mid-season, and a
        // page that parses short (a new footnote, say) never announces an earlier stage again.
        if ($stage <= Cache::get("cherry:stage:$year", 0)) {
            return null;
        }
        Cache::put("cherry:stage:$year", $stage, now()->addYear());

        $date = end($dates);
        $contest = Contest::whereNull('answer')->where(fn ($q) => $q->where('slug', 'like', '%bloom%')->orWhere('title', 'like', '%bloom%'))->latest('closes_at')->first();

        $body = $stage === 6
            ? "Peak bloom! The Park Service says 70% of the Tidal Basin Yoshinos opened $date. Go early, and take Metro to Smithsonian."
            : 'Bloom Watch: the Tidal Basin Yoshinos reached '.self::STAGES[$stage - 1]." on $date, stage $stage of 6 on the way to peak bloom.";

        if ($contest?->isOpen()) {
            $body .= " Still time to guess the peak in “{$contest->title}”: /contests/{$contest->slug}";
        }

        // Staff settle the contest themselves; picking winners isn't the bot's call.
        if ($stage === 6 && $contest) {
            User::where('is_staff', true)->each(fn (User $staff) => $staff->notify(new ModerationNotice(
                "@cherry: NPS reported peak bloom on $date. Set the answer for “{$contest->title}” in Admin → Moments.",
            )));
        }

        try {
            return $this->post(['line' => 'blue', 'stop' => 'D02', 'body' => $body]);
        } catch (Throwable $e) {
            Log::warning('@cherry could not post', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The dates NPS recorded for each stage this year, in stage order, from the Bloom Watch
     * table row that starts with the year ("2026 Mar 11 Mar 13 …"). Empty if the row isn't there.
     *
     * @return list<string>
     */
    public static function stages(string $html, int $year): array
    {
        // Tags become spaces so table cells don't run together.
        $text = preg_replace(['/<(script|style)\b.*?<\/\1>/is', '/<[^>]*>/'], ' ', $html);
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5));

        if (! preg_match('/\b'.$year.'((?:\s+(?:Feb|Mar|Apr)[a-z]*\.?\s+\d{1,2}\b\*?)+)/', (string) $text, $row)) {
            return [];
        }

        preg_match_all('/(Feb|Mar|Apr)[a-z]*\.?\s+(\d{1,2})/', $row[1], $dates, PREG_SET_ORDER);

        return array_slice(array_map(fn ($d) => ['Feb' => 'February', 'Mar' => 'March', 'Apr' => 'April'][$d[1]].' '.(int) $d[2], $dates), 0, 6);
    }
}
