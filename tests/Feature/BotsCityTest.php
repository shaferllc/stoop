<?php

namespace Tests\Feature;

use App\Bots\BikeshareBot;
use App\Bots\Bot;
use App\Bots\CherryBot;
use App\Bots\Dc311Bot;
use App\Bots\MetroBot;
use App\Bots\WeatherBot;
use App\Jobs\RunBots;
use App\Models\Contest;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotsCityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.wmata.key' => 'test-key']);
    }

    protected function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'America/New_York'));
    }

    protected function due(Bot $bot, string $name): bool
    {
        $schedule = new Schedule;
        $bot->schedule($schedule);
        $event = collect($schedule->events())->firstWhere('description', $name);

        return $event->isDue($this->app) && $event->filtersPass($this->app);
    }

    protected function mention(string $body): Dispatch
    {
        return Dispatch::factory()->create(['body' => $body, 'status' => 'approved']);
    }

    public function test_metro_answers_next_trains_by_station_name(): void
    {
        Http::fake(['api.wmata.com/StationPrediction.svc/*' => Http::response(['Trains' => [
            ['Line' => 'RD', 'Destination' => 'Shady Grove', 'Min' => '3'],
            ['Line' => 'RD', 'Destination' => 'Glenmont', 'Min' => 'BRD'],
        ]])]);

        $dispatch = $this->mention('@metro next dupont circle please');
        (new RunBots($dispatch))->handle();

        $reply = $dispatch->replies()->first();
        $this->assertSame('metro', $reply->user->username);
        $this->assertSame('Next trains at Dupont Circle: Red to Shady Grove, 3 min; Red to Glenmont, boarding.', $reply->body);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'GetPrediction/A03'));

        $this->assertStringContainsString('couldn’t find', (new MetroBot)->reply($this->mention('@metro next Narnia')));
        $this->assertStringContainsString('@metro next', (new MetroBot)->reply($this->mention('@metro hi')));
    }

    public function test_metro_next_resolves_codes_and_aliases(): void
    {
        Http::fake(['api.wmata.com/*' => Http::response(['Trains' => []])]);

        $this->assertSame('No trains predicted at Metro Center right now.', (new MetroBot)->reply($this->mention('@metro next C01')));
        config(['services.wmata.key' => null]);
        $this->assertStringContainsString('aren’t available', (new MetroBot)->reply($this->mention('@metro next Navy Yard')));
    }

    public function test_metro_posts_significant_incidents_once_and_respects_quiet_hours(): void
    {
        Http::fake(['api.wmata.com/Incidents.svc/*' => Http::response(['Incidents' => [
            ['IncidentID' => 'A', 'LinesAffected' => 'RD;', 'Description' => 'Red Line: Single tracking btwn Dupont Circle & Woodley Park due to track work.'],
            ['IncidentID' => 'B', 'LinesAffected' => 'BL; OR; SV;', 'Description' => 'Blue/Orange/Silver: Service suspended btwn Rosslyn & Foggy Bottom. Shuttle buses requested.'],
            ['IncidentID' => 'C', 'LinesAffected' => 'GR;', 'Description' => 'Green Line: Residual delays after an earlier train malfunction.'],
        ]])]);
        $bot = new MetroBot;

        // Overnight only the suspension goes out, once, on its first line.
        $this->at('2026-10-21 23:30');
        $this->assertSame(1, $bot->postIncidents());
        $post = $bot->account()->dispatches()->sole();
        $this->assertSame('blue', $post->line);
        $this->assertSame('C05', $post->stop);

        // In the morning the single-tracking catches up; the minor delay never posts.
        $this->at('2026-10-22 07:00');
        Cache::forget('wmata:incidents');
        $this->assertSame(1, $bot->postIncidents());
        $this->assertSame(0, $bot->postIncidents());
        $red = $bot->account()->dispatches()->where('line', 'red')->sole();
        $this->assertSame('A03', $red->stop);
        $this->assertSame(2, $bot->account()->dispatches()->count());

        $this->assertTrue($this->due($bot, 'bot:metro-incidents'));
    }

    public function test_bikeshare_answers_for_a_station_or_neighborhood(): void
    {
        Http::fake([
            'gbfs.lyft.com/*/station_information.json' => Http::response(['data' => ['stations' => [
                ['station_id' => '1', 'name' => 'Dupont Circle & New Hampshire Ave NW', 'lat' => 38.9101, 'lon' => -77.0436],
                ['station_id' => '2', 'name' => 'Calvert St & Woodley Pl NW', 'lat' => 38.9236, 'lon' => -77.0522],
            ]]]),
            'gbfs.lyft.com/*/station_status.json' => Http::response(['data' => ['stations' => [
                ['station_id' => '1', 'num_bikes_available' => 5, 'num_docks_available' => 10, 'is_renting' => true, 'vehicle_types_available' => [['vehicle_type_id' => '2', 'count' => 2]]],
                ['station_id' => '2', 'num_bikes_available' => 1, 'num_docks_available' => 3, 'is_renting' => true],
            ]]]),
        ]);
        $bot = new BikeshareBot;

        $this->assertSame('Capital Bikeshare near Dupont Circle: Dupont Circle & New Hampshire Ave NW (40 m): 5 bikes (2 e), 10 open docks.', $bot->reply($this->mention('@bikeshare Dupont')));
        $this->assertStringStartsWith('Capital Bikeshare near Woodley Park: Calvert St', $bot->reply($this->mention('@bikeshare adams morgan')));
        $this->assertSame('No Capital Bikeshare docks within 500 m of Shady Grove.', $bot->reply($this->mention('@bikeshare Shady Grove')));
        $this->assertStringContainsString('Tell me a Metro stop', $bot->reply($this->mention('@bikeshare')));
    }

    public function test_bikeshare_says_when_the_feed_is_down(): void
    {
        Http::fake(['gbfs.lyft.com/*' => Http::response('', 500)]);

        $this->assertStringContainsString('isn’t answering', (new BikeshareBot)->reply($this->mention('@bikeshare Navy Yard')));
    }

    protected function forecast(string $short, int $high = 67): array
    {
        return ['properties' => ['periods' => [
            ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => 49, 'shortForecast' => 'Clear'],
            ['name' => 'Wednesday', 'isDaytime' => true, 'temperature' => $high, 'shortForecast' => $short, 'detailedForecast' => "$short, with a high near $high."],
        ]]];
    }

    public function test_weather_posts_the_morning_forecast_once_a_day(): void
    {
        Http::fake(['api.weather.gov/gridpoints/LWX/97,71/forecast' => Http::response($this->forecast('Sunny'))]);
        $bot = new WeatherBot;

        $this->at('2026-10-21 06:30');
        $this->assertTrue($this->due($bot, 'bot:weather-morning'));
        $post = $bot->morningPost();
        $this->assertSame('silver', $post->line);
        $this->assertSame('Wednesday in DC: Sunny, high near 67°. Good day to take the long way across the Mall.', $post->body);
        $this->assertNull($bot->morningPost());
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent') && str_starts_with($request->header('User-Agent')[0], 'Stoop'));

        $this->at('2026-10-21 07:30');
        $this->assertFalse($this->due($bot, 'bot:weather-morning'));
    }

    public function test_weather_suggests_snow_day_to_staff_without_turning_it_on(): void
    {
        Http::fake(['api.weather.gov/*' => Http::response($this->forecast('Snow Likely', 30))]);
        $staff = User::factory()->create(['is_staff' => true]);
        $member = User::factory()->create();

        $this->at('2026-12-15 06:30');
        $post = (new WeatherBot)->morningPost();

        $this->assertStringContainsString('bread and milk', $post->body);
        $this->assertFalse(Settings::snowDay());
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertStringContainsString('Snow Day mode', $staff->notifications()->first()->data['message']);
        $this->assertSame(0, $member->notifications()->count());

        Settings::set('snow_day', true);
        Cache::flush();
        $this->assertFalse((new WeatherBot)->suggestSnowDay('Heavy snow', 'Snow'));
    }

    public function test_weather_posts_each_nws_alert_once(): void
    {
        $alert = fn ($id, $event, $status = 'Actual') => ['properties' => [
            'id' => $id, 'status' => $status, 'messageType' => 'Alert', 'event' => $event, 'headline' => "$event issued for the District of Columbia",
        ]];
        Http::fake(['api.weather.gov/alerts/*' => Http::response(['features' => [
            $alert('1', 'Heat Advisory'),
            $alert('2', 'Winter Storm Warning'),
            $alert('3', 'Test Message', 'Test'),
            $alert('4', 'Special Weather Statement'),
        ]])]);
        $staff = User::factory()->create(['is_staff' => true]);
        $bot = new WeatherBot;

        $this->at('2026-01-10 14:00');
        $this->assertSame(2, $bot->postAlerts());
        $this->assertSame(0, $bot->postAlerts());
        $this->assertSame('NWS: Heat Advisory issued for the District of Columbia.', $bot->account()->dispatches()->oldest('id')->first()->body);
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertFalse(Settings::snowDay());
    }

    protected function bloomPage(string $row): string
    {
        return '<html><script>var x = "2027 Mar 1";</script><body><h1>Bloom Watch</h1><table><tr><th>Year</th><th>Green Buds</th><th>Peak Bloom</th></tr>'
            .$row.'<tr><td>2026</td><td>Mar 11</td><td>Mar 13</td><td>Mar 16</td><td>Mar 21</td><td>Mar 22</td><td>Mar 26</td></tr></table></body></html>';
    }

    public function test_cherry_posts_new_bloom_stages_in_season_and_links_the_contest(): void
    {
        Http::fake(['www.nps.gov/*' => Http::response($this->bloomPage('<tr><td>2027</td><td>Mar 11</td><td>Mar&nbsp;14</td><td>Mar 18</td><td>*</td></tr>'))]);
        Contest::create(['slug' => 'peak-bloom-2027', 'title' => 'Peak Bloom 2027', 'question' => 'When?', 'closes_at' => Carbon::parse('2027-03-25')]);
        $bot = new CherryBot;

        $this->at('2027-03-20 09:05');
        $this->assertTrue($this->due($bot, 'bot:cherry'));
        $post = $bot->checkBloom();
        $this->assertSame('blue', $post->line);
        $this->assertSame('D02', $post->stop);
        $this->assertStringContainsString('extension of florets on March 18, stage 3 of 6', $post->body);
        $this->assertStringContainsString('/contests/peak-bloom-2027', $post->body);
        $this->assertNull($bot->checkBloom());

        $this->at('2027-03-20 23:05');
        $this->assertFalse($this->due($bot, 'bot:cherry'));
    }

    public function test_cherry_announces_peak_bloom_and_asks_staff_to_settle_the_contest(): void
    {
        Http::fake(['www.nps.gov/*' => Http::response($this->bloomPage('<tr><td>2027</td><td>Mar 1</td><td>Mar 4</td><td>Mar 9</td><td>Mar 15</td><td>Mar 19</td><td>Mar 24</td></tr>'))]);
        $contest = Contest::create(['slug' => 'bloom', 'title' => 'Peak Bloom 2027', 'question' => 'When?', 'closes_at' => Carbon::parse('2027-03-10')]);
        $staff = User::factory()->create(['is_staff' => true]);

        $this->at('2027-03-25 10:05');
        $post = (new CherryBot)->checkBloom();

        $this->assertStringStartsWith('Peak bloom! The Park Service says 70% of the Tidal Basin Yoshinos opened March 24.', $post->body);
        $this->assertStringNotContainsString('/contests/', $post->body);
        $this->assertNull($contest->fresh()->answer);
        $this->assertStringContainsString('Set the answer', $staff->notifications()->first()->data['message']);
    }

    public function test_cherry_stays_quiet_out_of_season_or_without_this_years_row(): void
    {
        Http::fake(['www.nps.gov/*' => Http::response($this->bloomPage(''))]);

        $this->at('2027-06-01 10:05');
        $this->assertNull((new CherryBot)->checkBloom());
        Http::assertNothingSent();

        $this->at('2027-03-02 10:05');
        $this->assertNull((new CherryBot)->checkBloom());
        $this->assertSame([], CherryBot::stages('<p>not the page you expected</p>', 2027));
    }

    public function test_311_explains_how_to_report_an_issue(): void
    {
        $bot = new Dc311Bot;

        $reply = $bot->reply($this->mention('@311 rats near Columbia Heights'));
        $this->assertStringContainsString('https://311.dc.gov as “DC Health Rodent & Vector Control” with the closest street address near Columbia Hts', $reply);
        $this->assertStringContainsString('“Pothole”', $bot->reply($this->mention('@311 big potholes at navy yard')));
        $this->assertStringContainsString('outside DC', $bot->reply($this->mention('@311 streetlight out near Bethesda')));
        $this->assertStringContainsString('pothole, streetlight, trash', $bot->reply($this->mention('@311 noisy neighbors near Shaw')));

        Http::fake(['*' => Http::response('')]);
        $dispatch = $this->mention('@311 graffiti by Eastern Market');
        (new RunBots($dispatch))->handle();
        $this->assertSame('311', $dispatch->replies()->sole()->user->username);
    }

    public function test_311_posts_a_weekly_sunday_summary(): void
    {
        Http::fake(['maps2.dcgis.dc.gov/*' => Http::sequence()
            ->push(['features' => [['attributes' => ['SERVICECODEDESCRIPTION' => 'Parking Enforcement', 'N' => 22]], ['attributes' => ['SERVICECODEDESCRIPTION' => 'DMV - Refunds - Tickets', 'N' => 9]]]])
            ->push(['features' => [['attributes' => ['SERVICECODEDESCRIPTION' => 'Pothole', 'N' => 4]]]])
            ->push(['features' => []])
            ->push(['features' => [['attributes' => ['SERVICECODEDESCRIPTION' => 'Graffiti Removal', 'N' => 3]]]])
            ->push(['features' => [['attributes' => ['SERVICECODEDESCRIPTION' => 'Pothole', 'N' => 2]]]])
            ->push(['error' => ['code' => 500]]),
        ]);
        $bot = new Dc311Bot;

        $this->at('2026-10-25 11:00');
        $this->assertTrue($this->due($bot, 'bot:311-weekly'));
        $post = $bot->weeklyPost();
        $this->assertSame('yellow', $post->line);
        $this->assertSame('A01', $post->stop);
        $this->assertStringContainsString('closed 31 311 requests', $post->body);
        $this->assertStringContainsString('22 parking enforcement, 6 pothole and 3 graffiti removal', $post->body);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), "SERVICEORDERSTATUS='Closed'"));

        // A broken response skips the week instead of posting.
        Cache::flush();
        $this->assertNull($bot->weeklyPost());

        $this->at('2026-10-26 11:00');
        $this->assertFalse($this->due($bot, 'bot:311-weekly'));
    }
}
