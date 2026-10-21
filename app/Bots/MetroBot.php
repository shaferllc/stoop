<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Metro;
use App\Support\Stations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @metro posts big WMATA rail disruptions on the line's topic, and answers "@metro next <station>".
 */
class MetroBot extends Bot
{
    // Disruptions worth a post, and the ones big enough to post during quiet hours too.
    protected const SIGNIFICANT = '/single[- ]?track|suspend|clos(ed|ure|ing)|no (train |rail )?service|shuttle bus|bypass|major|significant|\d{2,}[- ]?min/i';

    protected const MAJOR = '/suspend|clos(ed|ure|ing)|no (train |rail )?service|shuttle bus|major/i';

    public const DAILY_LIMIT = 6;

    public function username(): string
    {
        return 'metro';
    }

    public function name(): string
    {
        return 'Metro Alerts';
    }

    public function description(): string
    {
        return 'Major Metrorail delays, single-tracking and closures. Reply “@metro next Dupont Circle” for the next trains.';
    }

    public function reply(Dispatch $dispatch): ?string
    {
        if (! preg_match('/@metro\s+next\s+(.+)/i', $dispatch->body, $m)) {
            return 'Ask me “@metro next Dupont Circle” (or a station code like A03) for the next trains.';
        }

        if (! $code = Stations::find($m[1])) {
            return 'I couldn’t find that station. Try its name, like “@metro next Navy Yard”, or a code like F05.';
        }

        $name = Stations::name($code);
        $trains = Metro::nextTrains($code);

        if ($trains === null) {
            return "Live arrivals for $name aren’t available right now. Check wmata.com.";
        }

        if (! $trains) {
            return "No trains predicted at $name right now.";
        }

        return "Next trains at $name: ".collect($trains)->map(fn ($t) => trim(ucfirst((string) $t['line']).' to '.$t['destination']).', '.match ($t['minutes']) {
            'ARR' => 'arriving',
            'BRD' => 'boarding',
            default => is_numeric($t['minutes']) ? $t['minutes'].' min' : $t['minutes'],
        })->join('; ').'.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->postIncidents())->everyFiveMinutes()->name('bot:metro-incidents')->withoutOverlapping();
    }

    /**
     * Posts each significant incident once, on its first affected line. Returns how many posted.
     */
    public function postIncidents(): int
    {
        $posted = 0;
        $today = 'metro:posted:'.now(config('stoop.timezone'))->toDateString();

        foreach (collect(Metro::incidents() ?? [])->unique('id') as $incident) {
            $message = $incident['message'];

            // During quiet hours a minor incident waits; if it's still up at 6am it posts then.
            if (! preg_match(self::SIGNIFICANT, $message) || (self::quietHours() && ! preg_match(self::MAJOR, $message))) {
                continue;
            }

            if (Cache::get($today, 0) >= self::DAILY_LIMIT || ! Cache::add('metro:incident:'.$incident['id'], true, now()->addDays(2))) {
                continue;
            }

            try {
                // Safety posts never carry a sponsor.
                $this->post(['line' => $incident['line'], 'stop' => Stations::find($message), 'body' => Str::limit($message, 480), 'sponsorable' => false]);
                Cache::put($today, Cache::get($today, 0) + 1, now()->addDay());
                $posted++;
            } catch (Throwable $e) {
                Log::warning('@metro could not post an incident', ['error' => $e->getMessage()]);
            }
        }

        return $posted;
    }

    /** 10pm to 6am in DC. */
    public static function quietHours(): bool
    {
        $hour = now(config('stoop.timezone'))->hour;

        return $hour >= 22 || $hour < 6;
    }
}
