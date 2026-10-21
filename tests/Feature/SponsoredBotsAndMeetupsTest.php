<?php

namespace Tests\Feature;

use App\Bots\Calm;
use App\Bots\MetroBot;
use App\Bots\Quizmaster;
use App\Bots\Recap;
use App\Bots\WeatherBot;
use App\Jobs\RunBots;
use App\Livewire\Admin\MeetupSponsors;
use App\Livewire\Admin\Moments;
use App\Livewire\Admin\Presenters;
use App\Livewire\MeetupHost;
use App\Livewire\Pages\EventShow;
use App\Models\CityEvent;
use App\Models\Dispatch;
use App\Models\MeetupRedemption;
use App\Models\MeetupSponsor;
use App\Models\Place;
use App\Models\Presenter;
use App\Models\User;
use App\Support\Ads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SponsoredBotsAndMeetupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stoop.model_moderation' => false, 'services.openai.key' => null, 'services.anthropic.key' => null, 'services.wmata.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    protected function presenter(string $target, array $attributes = []): Presenter
    {
        return Presenter::create(['target' => $target, 'sponsor' => 'Bolt Coffee', 'url' => 'https://bolt.example/', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(), ...$attributes]);
    }

    protected function staff(string $role): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => [$role]]);
    }

    public function test_presented_by_goes_on_scheduled_bot_posts_but_not_replies(): void
    {
        $presenter = $this->presenter('bot:quizmaster');
        $this->presenter('bot:weather');

        $post = (new Quizmaster)->post(['line' => 'blue', 'body' => 'The Daily is up.']);
        $this->assertSame("The Daily is up.\nPresented by Bolt Coffee ".route('ads.click', ['presenter', $presenter->id]), $post->fresh()->body);
        $this->assertSame($presenter->id, $post->fresh()->details['presenter']);
        $this->assertNull($post->fresh()->link_preview_id, 'The click link is never fetched for a preview');

        // A bot's threaded post and its answer to a mention stay plain.
        $reply = (new Quizmaster)->post(['parentId' => $post->id, 'body' => 'Answers at noon.']);
        $this->assertStringNotContainsString('Presented by', $reply->fresh()->body);

        $question = Dispatch::factory()->create(['body' => '@weather will it rain later?', 'status' => 'approved']);
        (new RunBots($question))->handle();
        $answer = $question->replies()->sole();
        $this->assertSame('weather', $answer->user->username);
        $this->assertStringNotContainsString('Presented by', $answer->body);
    }

    public function test_safety_posts_never_carry_a_sponsor(): void
    {
        foreach (['bot:metro', 'bot:weather', 'bot:calm', 'bot:sentinel'] as $target) {
            $this->presenter($target);
        }
        $this->travelTo(now('America/New_York')->setTime(14, 0));
        Http::fake([
            'api.wmata.com/Incidents.svc/*' => Http::response(['Incidents' => [['IncidentID' => 'A', 'LinesAffected' => 'RD;', 'Description' => 'Red Line: Service suspended btwn Dupont Circle & Woodley Park.']]]),
            'api.weather.gov/alerts/*' => Http::response(['features' => [['properties' => ['id' => '1', 'status' => 'Actual', 'messageType' => 'Alert', 'event' => 'Heat Advisory', 'headline' => 'Heat Advisory issued for DC']]]]),
            'api.weather.gov/gridpoints/*' => Http::response(['properties' => ['periods' => [['name' => 'Today', 'isDaytime' => true, 'temperature' => 70, 'shortForecast' => 'Sunny']]]]),
        ]);

        $this->assertSame(1, (new MetroBot)->postIncidents());
        $this->assertSame(1, (new WeatherBot)->postAlerts());
        (new Calm)->post(['line' => 'blue', 'body' => 'Take a breath, neighbors.']);
        $this->assertSame(0, Dispatch::where('body', 'like', '%Presented by%')->count());

        // The same bot's everyday post does carry it.
        $this->assertStringContainsString('Presented by Bolt Coffee', (new WeatherBot)->morningPost()->fresh()->body);
    }

    public function test_the_line_never_pushes_a_post_past_the_length_limit(): void
    {
        $this->presenter('bot:quizmaster');
        $max = config('stoop.max_length');

        $long = (new Quizmaster)->post(['line' => 'blue', 'body' => str_repeat('a', $max - 10)])->fresh();
        $this->assertSame($max - 10, mb_strlen($long->body), 'Too long for the line: posted as written');
        $this->assertArrayNotHasKey('presenter', $long->details ?? []);

        $short = (new Quizmaster)->post(['line' => 'blue', 'body' => str_repeat('é', 300)])->fresh();
        $this->assertStringContainsString('Presented by', $short->body);
        $this->assertLessThanOrEqual($max, mb_strlen($short->body));

        // A full list post (like @weekend or @recap) drops whole items from the end to make room.
        $items = collect(range(1, 12))->map(fn ($i) => "• Item $i https://stoop.test/events/item-$i");
        $list = 'This weekend:'."\n".$items->takeWhile(fn ($v, $i) => mb_strlen($items->take($i + 1)->join("\n")) < $max - 20)->join("\n");
        $full = (new Quizmaster)->post(['line' => 'blue', 'body' => $list])->fresh();
        $this->assertStringContainsString('Presented by Bolt Coffee', $full->body);
        $this->assertLessThanOrEqual($max, mb_strlen($full->body));
        $this->assertMatchesRegularExpression('~item-\d+\nPresented by~', $full->body, 'No link is cut in half');
    }

    public function test_one_sponsor_per_bot_or_game_at_a_time(): void
    {
        $sales = $this->staff('sales');
        $form = fn (string $target, string $from, string $to) => Livewire::actingAs($sales)->test(Presenters::class)->call('edit')
            ->set('form.target', $target)->set('form.sponsor', 'Bolt Coffee')->set('form.url', 'https://bolt.example/')
            ->set('form.starts_on', $from)->set('form.ends_on', $to)->call('save');

        $form('daily', '2026-11-01', '2026-11-30')->assertHasNoErrors();
        $form('daily', '2026-11-20', '2026-12-10')->assertHasErrors('form.target');
        $form('daily', '2026-12-01', '2026-12-31')->assertHasNoErrors();
        $form('bot:weather', '2026-11-01', '2026-11-30')->assertHasNoErrors();
        $form('bot:calm', '2026-11-01', '2026-11-30')->assertHasErrors('form.target');

        Livewire::actingAs($sales)->test(Presenters::class)->call('edit')->set('form.target', 'linecup')->set('form.sponsor', '@acme')
            ->set('form.url', 'https://bolt.example/')->call('save')->assertHasErrors('form.sponsor');
        $this->assertSame(3, Presenter::count());
    }

    public function test_game_impressions_count_once_per_viewer_per_day_and_clicks_redirect(): void
    {
        $presenter = $this->presenter('daily');
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get(route('play'))->assertOk()->assertSee('Presented by')->assertSee('Bolt Coffee');
        $this->actingAs($viewer)->get(route('play'))->assertOk();
        $this->assertSame(1, $presenter->fresh()->impressions);
        $this->actingAs(User::factory()->create())->get(route('play'));
        $this->assertSame(2, $presenter->fresh()->impressions);

        $this->get(route('ads.click', ['presenter', $presenter->id]))->assertRedirect('https://bolt.example/');
        $this->assertSame(1, $presenter->fresh()->clicks);
        $this->assertSame(1, (int) DB::table('ad_daily')->where('kind', 'presenter')->value('clicks'));

        // In the inventory, with a daily CSV.
        $this->assertSame('Presented by', Ads::inventory()->firstWhere('kind', 'presenter')['type']);
        $this->actingAs($this->staff('sales'))->get(route('admin.ads.report', ['presenter', $presenter->id]))->assertOk()->assertSee('impressions');

        // A bot post with the line counts when its card is seen.
        $bot = $this->presenter('bot:recap');
        $post = app(Recap::class)->post(['line' => 'red', 'body' => 'Best of the week on Red.']);
        $this->actingAs($viewer)->get(route('dispatches.show', $post))->assertOk();
        $this->assertSame(1, $bot->fresh()->impressions);
    }

    protected function meetup(array $attributes = []): CityEvent
    {
        return CityEvent::create(['slug' => 'sits-'.Str::random(4), 'name' => 'Stoop Sits: Petworth', 'kind' => 'meetup', 'starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHour(), 'checkin_code' => 'PUPUSA', ...$attributes]);
    }

    public function test_sponsored_meetups_show_their_host_and_count_rsvps(): void
    {
        $meetup = $this->meetup();
        $place = Place::create(['name' => 'Hank’s Bar & Grill', 'owner_id' => User::factory()->create()->id]);

        Livewire::actingAs($this->staff('sales'))->test(MeetupSponsors::class)
            ->set('form.event_id', $meetup->id)->set('form.place_id', $place->id)->set('form.offer', 'First round half off')->set('form.url', 'https://hanks.example/')
            ->call('save')->assertHasNoErrors();
        $sponsor = MeetupSponsor::sole();

        Livewire::test(EventShow::class, ['event' => $meetup])->assertSee('Hosted by')->assertSee('Hank’s Bar &amp; Grill', false)->assertSee('Sponsored')->assertSee('First round half off');
        $this->get(route('happenings'))->assertOk()->assertSee('Hosted by Hank’s Bar &amp; Grill · Sponsored', false);
        $this->assertSame(1, $sponsor->fresh()->impressions, 'Same guest session, same day');

        $user = User::factory()->create();
        Livewire::actingAs($user)->test(EventShow::class, ['event' => $meetup])->call('rsvp')->assertSee('Going ✓');
        $this->assertSame(1, $meetup->rsvps()->count());

        $this->get(route('ads.click', ['meetup', $sponsor->id]))->assertRedirect('https://hanks.example/');
        $this->assertSame('Sponsored meetup', Ads::inventory()->firstWhere('kind', 'meetup')['type']);
    }

    public function test_only_checked_in_attendees_get_a_code_and_the_owner_marks_it_once(): void
    {
        $owner = User::factory()->create();
        $place = Place::create(['name' => 'Hank’s', 'owner_id' => $owner->id]);
        $meetup = $this->meetup();
        $sponsor = MeetupSponsor::create(['event_id' => $meetup->id, 'place_id' => $place->id, 'offer' => 'First round half off', 'url' => 'https://hanks.example/']);

        $browser = User::factory()->create();
        Livewire::actingAs($browser)->test(EventShow::class, ['event' => $meetup])->assertDontSeeHtml('data-offer-code');
        $this->assertSame(0, MeetupRedemption::count());

        $guest = User::factory()->create();
        Livewire::actingAs($guest)->test(EventShow::class, ['event' => $meetup])->set('code', 'pupusa')->call('checkIn')->assertHasNoErrors()->assertSeeHtml('data-offer-code');
        $code = MeetupRedemption::sole()->code;

        // Another venue's owner can't redeem it; a stranger can't open the panel.
        $other = User::factory()->create();
        Place::create(['name' => 'Elsewhere', 'owner_id' => $other->id]);
        Livewire::actingAs($other)->test(MeetupHost::class, ['placeId' => Place::where('owner_id', $other->id)->value('id')])->set('code', $code)->call('redeem')->assertHasErrors('code');
        Livewire::actingAs($browser)->test(MeetupHost::class, ['placeId' => $place->id])->assertForbidden();

        $host = Livewire::actingAs($owner)->test(MeetupHost::class, ['placeId' => $place->id])->assertSee('Stoop Sits: Petworth');
        $host->set('code', strtolower($code))->call('redeem')->assertHasNoErrors();
        $this->assertNotNull(MeetupRedemption::sole()->redeemed_at);
        $host->set('code', $code)->call('redeem')->assertHasErrors('code');

        $this->actingAs($owner)->get(route('places.show', $place))->assertOk()->assertSee('Meetups you host');
        $this->actingAs($browser)->get(route('places.show', $place))->assertOk()->assertDontSee('Meetups you host');
        $this->assertSame(1, $sponsor->redemptions()->whereNotNull('redeemed_at')->count());
    }

    public function test_role_gates(): void
    {
        $editor = $this->staff('editor');
        Livewire::actingAs($editor)->test(Presenters::class)->assertForbidden();
        Livewire::actingAs($editor)->test(MeetupSponsors::class)->assertForbidden();
        Livewire::actingAs($editor)->test(Moments::class)->assertOk()->assertDontSee('Sponsored meetups');
        Livewire::actingAs(User::factory()->create())->test(Presenters::class)->assertForbidden();

        $sales = $this->staff('sales');
        Livewire::actingAs($sales)->test(Presenters::class)->assertOk();
        Livewire::actingAs($sales)->test(MeetupSponsors::class)->assertOk();
        $this->actingAs($sales)->get(route('admin.ads'))->assertOk()->assertSee('Presented by');
        $this->actingAs($editor)->get(route('admin.ads'))->assertForbidden();
        $this->actingAs($this->staff('admin'))->get(route('admin.moments'))->assertOk()->assertSee('Sponsored meetups');
    }
}
