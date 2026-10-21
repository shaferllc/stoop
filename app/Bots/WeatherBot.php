<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @weather posts a morning forecast and DC's NWS warnings and advisories, from the free
 * National Weather Service API. When snow is coming it suggests Snow Day mode to staff;
 * it never turns it on.
 */
class WeatherBot extends Bot
{
    // The NWS forecast grid square over downtown DC (from api.weather.gov/points/38.8951,-77.0364).
    public const FORECAST = 'https://api.weather.gov/gridpoints/LWX/97,71/forecast';

    public const ALERTS = 'https://api.weather.gov/alerts/active?area=DC&status=actual&message_type=alert';

    // The NWS's human-readable forecast for the same spot, linked from mention replies.
    public const PAGE = 'https://forecast.weather.gov/MapClick.php?lat=38.8951&lon=-77.0364';

    protected const SNOW = '/snow|blizzard|winter storm|winter weather|ice storm|sleet|freezing rain/i';

    public const DAILY_ALERTS = 3;

    public function username(): string
    {
        return 'weather';
    }

    public function name(): string
    {
        return 'DC Weather';
    }

    public function description(): string
    {
        return 'The morning forecast and National Weather Service warnings for DC. Mention @weather for the forecast right now.';
    }

    /**
     * The current and next NWS forecast periods, plus any active DC alerts.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        $period = fn (array $p) => "{$p['name']}: {$p['shortForecast']}, ".($p['isDaytime'] ? 'high' : 'low')." {$p['temperature']}°"
            .(isset($p['windSpeed']) ? ', wind '.$p['windSpeed'].(isset($p['windDirection']) ? ' '.$p['windDirection'] : '') : '').'.';
        $periods = collect($this->get(self::FORECAST, 'properties.periods'))->filter(fn ($p) => isset($p['name'], $p['shortForecast'], $p['temperature']))->take(2);
        $alerts = collect($this->get(self::ALERTS, 'features'))->map(fn ($f) => $f['properties']['event'] ?? null)->filter()->unique();

        $body = $periods->isEmpty()
            ? 'The National Weather Service forecast isn’t answering right now.'
            : 'DC weather. '.$periods->map($period)->join(' ');
        $body .= $alerts->isEmpty() ? ' No active NWS alerts for DC.' : ' NWS alerts: '.$alerts->take(3)->join(', ').'.';

        return Str::limit($body, 400).' '.self::PAGE;
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->morningPost())->dailyAt('6:30')->timezone(config('stoop.timezone'))->name('bot:weather-morning');
        $schedule->call(fn () => $this->postAlerts())->everyFifteenMinutes()->name('bot:weather-alerts')->withoutOverlapping();
    }

    /**
     * Today's forecast in one line, once a day.
     */
    public function morningPost(): ?Dispatch
    {
        $today = collect($this->get(self::FORECAST, 'properties.periods'))->firstWhere('isDaytime', true);
        if (! $today || ! isset($today['shortForecast'], $today['temperature'])) {
            return null;
        }

        $this->suggestSnowDay($today['shortForecast'].' '.($today['detailedForecast'] ?? ''), $today['name'].': '.$today['shortForecast']);

        if (! Cache::add('weather:morning:'.now(config('stoop.timezone'))->toDateString(), true, now()->addDay())) {
            return null;
        }

        return $this->publish([
            'line' => 'silver',
            'body' => "{$today['name']} in DC: {$today['shortForecast']}, high near {$today['temperature']}°. ".self::quip($today['shortForecast'], (int) $today['temperature']),
        ]);
    }

    /**
     * Posts each new NWS warning, watch or advisory for DC once. Returns how many posted.
     */
    public function postAlerts(): int
    {
        $posted = 0;
        $today = 'weather:alerts:'.now(config('stoop.timezone'))->toDateString();

        foreach ($this->get(self::ALERTS, 'features') ?? [] as $feature) {
            $alert = $feature['properties'] ?? [];
            $event = (string) ($alert['event'] ?? '');
            if (($alert['status'] ?? '') !== 'Actual' || ($alert['messageType'] ?? '') !== 'Alert' || ! preg_match('/warning|watch|advisory/i', $event)) {
                continue;
            }

            if (preg_match('/winter storm|winter weather|blizzard|ice storm|snow/i', $event)) {
                $this->suggestSnowDay($event, 'NWS issued a '.$event);
            }

            if (Cache::get($today, 0) >= self::DAILY_ALERTS || ! Cache::add('weather:alert:'.($alert['id'] ?? md5($event)), true, now()->addDays(3))) {
                continue;
            }

            if ($this->publish(['line' => 'silver', 'body' => Str::limit('NWS: '.($alert['headline'] ?? $event).'.', 480), 'sponsorable' => false])) {
                Cache::put($today, Cache::get($today, 0) + 1, now()->addDay());
                $posted++;
            }
        }

        return $posted;
    }

    /**
     * Tells staff that Snow Day mode might be worth turning on, at most once a day.
     */
    public function suggestSnowDay(string $forecast, string $reason): bool
    {
        if (! preg_match(self::SNOW, $forecast) || Settings::snowDay()
            || ! Cache::add('weather:snow-suggested:'.now(config('stoop.timezone'))->toDateString(), true, now()->addDay())) {
            return false;
        }

        User::where('is_staff', true)->each(fn (User $staff) => $staff->notify(new ModerationNotice(
            "@weather: $reason. Consider turning on Snow Day mode in Admin → Moments.",
        )));

        return true;
    }

    public static function quip(string $forecast, int $high): string
    {
        return match (true) {
            (bool) preg_match('/snow|blizzard/i', $forecast) => 'Somewhere a Giant is already out of bread and milk.',
            (bool) preg_match('/rain|shower|storm|drizzle/i', $forecast) => 'Umbrella day. Mind the slick escalators.',
            $high >= 90 => 'Swamp season: hydrate, and stand right on the escalator.',
            $high <= 32 => 'The platform wait will feel long. Layer up.',
            (bool) preg_match('/fog/i', $forecast) => 'The Monument is playing hide and seek.',
            (bool) preg_match('/sunny|clear/i', $forecast) => 'Good day to take the long way across the Mall.',
            default => 'Have a good one, DC.',
        };
    }

    protected function get(string $url, string $key): ?array
    {
        try {
            return Cache::remember('weather:'.md5($url), now()->addMinutes(10), fn () => Http::withHeaders([
                'User-Agent' => 'Stoop ('.config('app.url').')',
                'Accept' => 'application/geo+json',
            ])->timeout(6)->get($url)->throw()->json($key));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function publish(array $data): ?Dispatch
    {
        try {
            return $this->post($data);
        } catch (Throwable $e) {
            Log::warning('@weather could not post', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
