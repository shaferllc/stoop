<?php

namespace Tests\Feature;

use App\Bots\Bot;
use App\Bots\MetroBot;
use App\Jobs\RunBots;
use App\Models\Dispatch;
use App\Models\Happening;
use App\Models\User;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotAnswersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null, 'services.openai.key' => null, 'services.wmata.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api.weather.gov/gridpoints/*' => Http::response(['properties' => ['periods' => [
                ['name' => 'This Afternoon', 'isDaytime' => true, 'temperature' => 68, 'windSpeed' => '10 mph', 'windDirection' => 'NW', 'shortForecast' => 'Sunny'],
                ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => 49, 'windSpeed' => '5 mph', 'windDirection' => 'N', 'shortForecast' => 'Mostly Clear'],
            ]]]),
            'api.weather.gov/alerts/*' => Http::response(['features' => [['properties' => ['event' => 'Wind Advisory', 'status' => 'Actual', 'messageType' => 'Alert']]]]),
            'api.wmata.com/Incidents.svc/*' => Http::response(['Incidents' => [['IncidentID' => 'X1', 'LinesAffected' => 'RD;', 'Description' => 'Red Line: Trains single tracking between Silver Spring and Takoma.']]]),
            'api.wmata.com/StationPrediction.svc/*' => Http::response(['Trains' => []]),
            'www.nps.gov/*' => Http::response('<table><tr><td>2026</td><td>Mar 11</td><td>Mar 15</td><td>Mar 19</td><td>Mar 22</td><td>Mar 25</td><td>Mar 28</td></tr></table>'),
            'gbfs.lyft.com/*' => Http::response(['data' => ['stations' => []]]),
        ]);
        // A Wednesday afternoon in October: out of cherry season, Metro open.
        $this->at('2026-10-21 14:00');
    }

    protected function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'America/New_York'));
    }

    protected function ask(string $body): ?Dispatch
    {
        $dispatch = Dispatch::factory()->create(['body' => $body, 'status' => 'approved', 'stop' => null]);
        (new RunBots($dispatch))->handle();

        return $dispatch->replies()->with('user')->first();
    }

    public function test_stoop_greets_answers_and_points_to_a_page(): void
    {
        $hello = $this->ask('@stoop hello');
        $this->assertSame('stoop', $hello->user->username);
        $this->assertStringContainsString('“@metro next Dupont”', $hello->body);
        $this->assertStringContainsString(route('play'), $hello->body);
        $this->assertStringContainsString(route('bots'), $hello->body);

        $this->assertStringContainsString('10 a day', $this->ask('@stoop how do half-smokes work?')->body);
        $this->assertStringContainsString(route('play'), $this->ask('@stoop when is the trivia night?')->body);
        $this->assertStringNotContainsString('not sure', $this->ask('@stoop qwerty zxcv')->body);
    }

    public function test_metro_says_when_it_is_closed_and_lists_delays(): void
    {
        $this->at('2026-10-21 01:13');
        $reply = $this->ask('@metro next A03')->body;
        $this->assertStringStartsWith('Metro is closed right now. The first trains run at 5am.', $reply);
        $this->assertStringContainsString('Red: Red Line: Trains single tracking', $reply);
        $this->assertStringContainsString(route('stops.show', 'A03'), $reply);

        // Friday's late close, and Sunday's late start.
        $this->at('2026-10-24 00:30');
        $this->assertSame('', MetroBot::closedNotice());
        $this->at('2026-10-25 01:30');
        $this->assertSame('Metro is closed right now. The first trains run at 7am.', MetroBot::closedNotice());
        $this->at('2026-10-21 23:59');
        $this->assertSame('', MetroBot::closedNotice());

        $this->at('2026-10-21 14:00');
        $this->assertStringContainsString('Red: Red Line', $this->ask('@metro delays?')->body);
        $this->assertSame('No trains predicted at Dupont Circle right now.', $this->ask('@metro next train at Dupont Circle')->body);
    }

    public function test_weather_mention_gives_the_forecast_and_alerts(): void
    {
        $reply = $this->ask('Hey @weather, jacket today?')->body;

        $this->assertStringContainsString('This Afternoon: Sunny, high 68°, wind 10 mph NW.', $reply);
        $this->assertStringContainsString('Tonight: Mostly Clear, low 49°', $reply);
        $this->assertStringContainsString('NWS alerts: Wind Advisory.', $reply);
    }

    public function test_cherry_out_of_season_explains_the_season_and_last_peak(): void
    {
        $reply = $this->ask('Hi @cherry')->body;

        $this->assertStringContainsString('March 1 to April 15', $reply);
        $this->assertStringContainsString('late March and early April', $reply);
        $this->assertStringContainsString('the last one was March 28, 2026', $reply);

        // In season, the latest stage.
        $this->at('2026-03-30 10:00');
        $this->assertStringContainsString('stage 6 of 6, peak bloom, since March 28. Peak bloom!', $this->ask('@cherry how are the blossoms?')->body);
    }

    public function test_ask_lists_whats_happening_today(): void
    {
        $show = Dispatch::factory()->create(['type' => 'happening', 'happening_at' => 'Tonight · 8pm', 'body' => 'Go-go night at the Black Cat with @someone', 'stop' => 'E02']);
        Happening::create(['dispatch_id' => $show->id, 'starts_at' => Carbon::parse('2026-10-21 20:00', 'America/New_York')->utc()]);

        $reply = $this->ask('@ask whats happening today')->body;

        $this->assertStringStartsWith('Happening today:', $reply);
        $this->assertStringContainsString('Go-go night at the Black Cat with someone (8:00pm) '.route('dispatches.show', $show), $reply);
        $this->assertStringContainsString(route('happenings'), $reply);
        $this->assertStringContainsString('Nothing on the Happenings calendar this weekend', $this->ask('@ask anything going on this weekend?')->body);
    }

    public function test_magazine_mentions_list_the_latest_articles(): void
    {
        $magazine = User::factory()->create(['username' => 'magazine', 'is_staff' => true]);
        foreach (['Old story', 'Best tacos', 'New Metro map', 'Fall festivals'] as $i => $title) {
            $post = Dispatch::factory()->create(['user_id' => $magazine->id, 'body' => $title]);
            DB::table('articles')->insert(['guid_hash' => "g$i", 'url' => "https://magazine.example/$i", 'title' => $title, 'dispatch_id' => $post->id, 'published_at' => now()->subDays(4 - $i), 'created_at' => now(), 'updated_at' => now()]);
        }

        $reply = $this->ask('@magazine what’s new?');

        $this->assertSame('magazine', $reply->user->username);
        $this->assertStringStartsWith("Latest from the magazine:\n1. Fall festivals ".route('dispatches.show', $post), $reply->body);
        $this->assertStringContainsString('3. Best tacos', $reply->body);
        $this->assertStringNotContainsString('Old story', $reply->body);
    }

    public function test_every_bot_gives_a_real_answer_to_a_bare_mention(): void
    {
        $bots = collect(Bot::all())->map->username()->all();

        foreach (Bot::all() as $bot) {
            if (in_array($bot->username(), ['calm', 'welcome', 'sentinel'], true)) {
                continue;
            }

            foreach (['@'.$bot->username(), '@'.$bot->username().' help'] as $body) {
                $reply = $this->ask($body);
                $this->assertNotNull($reply, "$body got no reply");
                $this->assertNotSame($bot->help(), $reply->body, "$body only got the how-to");
                $this->assertLessThanOrEqual(500, mb_strlen($reply->body), $body);
                // Only bots get mentioned, never people.
                $this->assertSame([], array_diff(Mentions::usernames($reply->body), $bots), $body);
            }
        }
    }
}
