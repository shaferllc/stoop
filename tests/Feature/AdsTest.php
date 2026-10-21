<?php

namespace Tests\Feature;

use App\Livewire\Admin\Ads as AdsPage;
use App\Livewire\Pages\BallotShow;
use App\Livewire\Pages\Feed;
use App\Models\Ballot;
use App\Models\BallotPromotion;
use App\Models\Dispatch;
use App\Models\SponsoredDispatch;
use App\Models\Sponsorship;
use App\Models\User;
use App\Support\Ads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stoop.model_moderation' => false, 'services.openai.key' => null, 'services.anthropic.key' => null]);
        Http::preventStrayRequests();
    }

    protected function ad(array $attributes = []): SponsoredDispatch
    {
        return SponsoredDispatch::create([
            'advertiser' => 'Acme Bikes', 'body' => 'Tune-ups $40 this week', 'url' => 'https://acme.example/tuneup',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(), 'daily_cap' => 5, ...$attributes,
        ]);
    }

    protected function posts(int $n, array $attributes = []): void
    {
        Dispatch::factory()->count($n)->create(['line' => 'red', 'stop' => 'E03', ...$attributes]);
    }

    protected function adsIn(string $html): int
    {
        return substr_count($html, 'data-ad ');
    }

    protected function staff(string $role): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => [$role]]);
    }

    public function test_feeds_get_at_most_one_ad_per_ten_dispatches(): void
    {
        $this->actingAs(User::factory()->create());
        $this->ad(['advertiser' => 'Acme Bikes']);
        $this->ad(['advertiser' => 'Bolt Coffee']);
        $this->ad(['advertiser' => 'Capital Pies']);

        $this->posts(9);
        $this->assertSame(0, $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()));

        $this->posts(16);
        $feed = Livewire::test(Feed::class, ['tab' => 'latest']);
        $this->assertSame(2, $this->adsIn($feed->html()));
        $this->assertStringContainsString('Sponsored · ', $feed->html());
        $this->assertStringContainsString('rel="sponsored noopener"', $feed->html());

        // Load more adds the third slot; polling keeps the same picks without recounting.
        $shows = DB::table('ad_views')->sum('shows');
        $feed->call('$refresh');
        $feed->call('adAt', 9);
        $this->assertSame($shows, DB::table('ad_views')->sum('shows'), 'Neither polling nor a made-up slot counts');
        $feed->call('loadMore');
        $this->assertSame(2, $this->adsIn($feed->html()), 'Only 25 dispatches, so still two slots');
    }

    public function test_ads_never_show_in_threads_notifications_or_messages(): void
    {
        $user = User::factory()->create();
        $this->ad();
        $this->posts(12);
        $dispatch = Dispatch::first();
        Dispatch::factory()->count(12)->create(['parent_id' => $dispatch->id, 'line' => 'red']);

        $this->actingAs($user)->get(route('dispatches.show', $dispatch))->assertOk()->assertDontSee('Acme Bikes');
        $this->get(route('notifications'))->assertOk()->assertDontSee('Acme Bikes');
        $this->get(route('messages'))->assertOk()->assertDontSee('Acme Bikes');
    }

    public function test_targeting_is_by_the_stop_or_topic_being_read(): void
    {
        $this->actingAs(User::factory()->create());
        $this->ad(['advertiser' => 'Stop Ad', 'stops' => ['E03']]);
        $this->ad(['advertiser' => 'Line Ad', 'lines' => ['blue']]);
        $this->posts(10);
        $this->posts(10, ['line' => 'blue', 'stop' => 'A01']);

        $html = Livewire::test(Feed::class, ['stop' => 'E03'])->html();
        $this->assertStringContainsString('Sponsored · Stop Ad', $html);
        $this->assertStringContainsString('Shown to people near '.config('stoop.stations.E03'), $html);
        $this->assertStringNotContainsString('Line Ad', $html);

        $html = Livewire::test(Feed::class, ['line' => 'blue'])->html();
        $this->assertStringContainsString('Sponsored · Line Ad', $html);
        $this->assertStringContainsString('Shown on the '.config('stoop.lines.blue.name').' topic', $html);
        $this->assertStringNotContainsString('Stop Ad', $html);

        $this->assertSame(0, $this->adsIn(Livewire::test(Feed::class, ['stop' => 'A01'])->html()));
    }

    public function test_daily_frequency_cap_and_impressions_once_per_viewer_per_day(): void
    {
        $ad = $this->ad(['daily_cap' => 2]);
        $this->posts(10);
        $user = User::factory()->create();
        $this->actingAs($user);

        $seen = collect(range(1, 3))->map(fn () => $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()))->all();
        $this->assertSame([1, 1, 0], $seen);
        $this->assertSame(1, $ad->fresh()->impressions, 'Shown twice, counted once');

        $this->actingAs(User::factory()->create());
        Livewire::test(Feed::class, ['tab' => 'latest']);
        $this->assertSame(2, $ad->fresh()->impressions);

        $this->travel(1)->day();
        $this->actingAs($user);
        $this->assertSame(1, $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()), 'The cap resets the next day');
        $this->assertSame(3, $ad->fresh()->impressions);
        $this->assertSame([2, 1], Ads::report('dispatch', $ad->id)->pluck('impressions')->all());
    }

    public function test_ads_stop_at_their_goal_and_outside_their_dates(): void
    {
        $this->posts(10);
        $this->ad(['goal' => 5])->forceFill(['impressions' => 5])->save();
        $this->ad(['starts_at' => now()->addDay()]);
        $this->ad(['ends_at' => now()->subMinute()]);

        $this->assertSame(0, $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()));
    }

    public function test_sponsorship_views_count_once_per_viewer_per_day(): void
    {
        $sponsorship = Sponsorship::create(['sponsor' => 'Ben’s', 'stop' => 'E03', 'headline' => 'Half-smokes', 'starts_on' => today(), 'ends_on' => today()->addWeek()]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Feed::class, ['stop' => 'E03'])->assertSee('Sponsored · Ben’s')->assertSee('Why am I seeing this?');
        Livewire::test(Feed::class, ['stop' => 'E03']);
        $this->assertSame(1, $sponsorship->fresh()->impressions);

        $this->get(route('sponsorships.click', $sponsorship))->assertRedirect(route('home'));
        $this->assertSame(1, $sponsorship->fresh()->clicks);
        $this->assertSame([1], Ads::report('sponsorship', $sponsorship->id)->pluck('clicks')->all());
    }

    public function test_clicks_redirect_only_to_the_stored_link(): void
    {
        $ad = $this->ad();

        $this->get(route('ads.click', ['dispatch', $ad->id]).'?url=https://evil.example&to=https://evil.example')
            ->assertRedirect('https://acme.example/tuneup');
        $this->assertSame(1, $ad->fresh()->clicks);
        $this->assertSame(1, (int) DB::table('ad_daily')->where('kind', 'dispatch')->where('ad_id', $ad->id)->value('clicks'));

        $this->get('/ad/dispatch/999')->assertNotFound();
        $this->get('/ad/sponsorship/'.$ad->id)->assertNotFound();
        $this->get('/ad/user/1')->assertNotFound();
    }

    public function test_readers_can_hide_and_report_an_ad(): void
    {
        $ad = $this->ad();
        $this->posts(10);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Feed::class, ['tab' => 'latest'])->call('hideAd', 'dispatch', $ad->id)->assertDontSee('Acme Bikes');
        $this->assertSame(0, $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()));

        $other = $this->ad(['advertiser' => 'Bolt Coffee']);
        Livewire::test(Feed::class, ['tab' => 'latest'])->call('hideAd', 'dispatch', $other->id, 'misleading');
        $this->assertDatabaseHas('ad_hides', ['kind' => 'dispatch', 'ad_id' => $other->id, 'user_id' => $user->id, 'reason' => 'misleading']);
        Livewire::test(Feed::class, ['tab' => 'latest'])->call('hideAd', 'dispatch', $other->id, 'bogus')->assertForbidden();

        $row = Ads::inventory()->firstWhere('id', $other->id);
        $this->assertSame([1, 1], [$row['hides'], $row['reports']]);
    }

    public function test_guests_hide_for_the_session_but_cannot_report(): void
    {
        $ad = $this->ad();
        $this->posts(10);

        Livewire::test(Feed::class, ['tab' => 'latest'])->call('hideAd', 'dispatch', $ad->id);
        $this->assertSame(0, $this->adsIn(Livewire::test(Feed::class, ['tab' => 'latest'])->html()));
        Livewire::test(Feed::class, ['tab' => 'latest'])->call('hideAd', 'dispatch', $ad->id, 'scam')->assertForbidden();
        $this->assertDatabaseCount('ad_hides', 0);
    }

    public function test_ads_are_not_dispatches_and_never_reach_bots_points_or_moderation(): void
    {
        Storage::fake('public');
        $sales = $this->staff('sales');
        $this->actingAs($sales);

        Livewire::test(AdsPage::class)->call('edit')
            ->set('form.advertiser', 'Acme Bikes')->set('form.body', '@stoop @eats best bikes near E03')
            ->set('form.url', 'https://acme.example')->set('form.stops', ['E03'])->set('image', UploadedFile::fake()->image('ad.jpg', 600, 400))
            ->call('save')->assertHasNoErrors();
        $ad = SponsoredDispatch::sole();
        $this->assertNotNull($ad->image_path);
        Storage::disk('public')->assertExists($ad->image_path);
        $this->assertSame(0, Dispatch::count());

        $this->posts(10);
        $this->actingAs(User::factory()->create());
        Livewire::test(Feed::class, ['stop' => 'E03'])->assertSee('Sponsored · Acme Bikes');
        $this->assertSame(10, Dispatch::count(), 'Serving it made no dispatch, so no bot reply either');
        $this->assertFalse(Dispatch::where('body', 'like', '%best bikes%')->exists());

        $this->actingAs($this->staff('moderator'))->get(route('moderation'))->assertOk()->assertDontSee('Acme Bikes');
    }

    public function test_sales_role_gate_on_web_and_api(): void
    {
        $ad = $this->ad();

        $this->actingAs($this->staff('moderator'));
        $this->get(route('admin.ads'))->assertForbidden();
        $this->get(route('admin.ads.csv'))->assertForbidden();
        $this->get(route('admin.ads.report', ['dispatch', $ad->id]))->assertForbidden();

        $this->actingAs($this->staff('sales'));
        $this->get(route('admin.ads'))->assertOk()->assertSee('Acme Bikes');
        $this->get(route('admin.ads.csv'))->assertOk();

        Sanctum::actingAs($this->staff('editor'), ['admin']);
        $this->getJson('/api/admin/ads')->assertForbidden();

        Sanctum::actingAs($this->staff('sales'), ['admin']);
        $this->getJson('/api/admin/ads')->assertOk()->assertJsonPath('placements.0.name', 'Acme Bikes: Tune-ups $40 this week');
        $this->getJson("/api/admin/ads/dispatch/{$ad->id}")->assertOk()->assertJsonPath('impressions', 0);
        $this->getJson('/api/admin/ads/dispatch/999')->assertNotFound();
        $this->getJson('/api/admin/me')->assertJsonPath('roles', ['sales']);
    }

    public function test_reports_by_day_and_inventory_csv(): void
    {
        $ad = $this->ad(['advertiser' => '=cmd', 'goal' => 100]);
        $this->posts(10);
        $this->actingAs(User::factory()->create());
        Livewire::test(Feed::class, ['tab' => 'latest']);
        $this->get(route('ads.click', ['dispatch', $ad->id]));
        $this->get(route('ads.click', ['dispatch', $ad->id]));

        $this->assertEquals([(object) ['day' => now(config('stoop.timezone'))->toDateString(), 'impressions' => 1, 'clicks' => 2, 'ctr' => 200.0]], Ads::report('dispatch', $ad->id)->all());

        // Every kind of placement shows in the inventory.
        $ballot = Ballot::start('Best of Washington 2027', now()->subDay(), now()->addWeek(), []);
        BallotPromotion::create(['ballot_id' => $ballot->id, 'title' => 'The Party', 'event_on' => now()->addMonth()->toDateString(), 'venue' => 'The Anthem', 'url' => 'https://tickets.example']);
        Sponsorship::create(['sponsor' => 'Ben’s', 'stop' => 'E03', 'headline' => 'Half-smokes', 'starts_on' => today()->addDay(), 'ends_on' => today()->addWeek()]);
        Dispatch::factory()->create(['type' => 'callout', 'sponsor' => 'Pepco']);
        Dispatch::factory()->create(['type' => 'happening'])->happening()->create(['featured' => true, 'starts_at' => now()->addDay()]);
        Dispatch::factory()->create(['type' => 'openhouse', 'details' => ['sponsored' => true, 'date' => now()->addDays(3)->toDateString(), 'block' => '1400 block of Q St NW']]);
        $this->assertEqualsCanonicalizing(['dispatch', 'party', 'sponsorship', 'callout', 'happening', 'openhouse'], Ads::inventory()->pluck('kind')->all());
        $this->assertSame('upcoming', Ads::inventory()->firstWhere('kind', 'sponsorship')['status']);

        $this->actingAs($this->staff('sales'));
        $csv = $this->get(route('admin.ads.report', ['dispatch', $ad->id]))->assertOk()->getContent();
        $this->assertStringContainsString('day,impressions,clicks,ctr_percent', $csv);
        $this->assertStringContainsString(',1,2,200', $csv);
        $inventory = $this->get(route('admin.ads.csv'))->getContent();
        $this->assertStringContainsString("'=cmd", $inventory, 'Formula cells are made plain text');
        $this->assertStringContainsString('The Party', $inventory);
        Livewire::test(AdsPage::class, ['report' => 'dispatch:'.$ad->id])->assertSee('Daily report')->assertSee('200%');
    }

    public function test_party_tickets_on_the_ballot_and_in_feeds(): void
    {
        $ballot = Ballot::start('Best of Washington 2027', now()->subDay(), now()->addWeek(), []);
        $this->actingAs($this->staff('sales'));
        Livewire::test(AdsPage::class)->call('editParty')
            ->set('party.ballot_id', $ballot->id)->set('party.title', 'Best of Washington Party')->set('party.event_on', now()->addMonth()->toDateString())
            ->set('party.venue', 'The Anthem')->set('party.url', 'https://tickets.example/bow')->set('party.price', 'From $85')
            ->call('saveParty')->assertHasNoErrors();
        $party = BallotPromotion::sole();

        $this->actingAs(User::factory()->create());
        Livewire::test(BallotShow::class, ['ballot' => $ballot])->assertSee('Best of Washington Party')->assertSee('From $85')->assertSee(route('ads.click', ['party', $party->id]));
        $this->posts(10);
        Livewire::test(Feed::class, ['tab' => 'latest'])->assertSee('From the magazine')->assertSee('Best of Washington Party');

        $this->get(route('ads.click', ['party', $party->id]))->assertRedirect('https://tickets.example/bow');
        $this->assertSame(1, $party->fresh()->clicks);

        // Shown until the party even after voting closes, then gone.
        $this->travel(2)->weeks();
        $this->assertTrue(BallotPromotion::running()->exists());
        $this->travel(1)->month();
        $this->assertFalse(BallotPromotion::running()->exists());
    }
}
