<?php

namespace Tests\Feature;

use App\Jobs\RunBots;
use App\Livewire\Admin\Places as PlacesPage;
use App\Livewire\Admin\Referrals as ReferralsPage;
use App\Livewire\PlaceReservation;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Referrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReferralsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config([
            'services.anthropic.key' => null, 'services.openai.key' => null, 'stoop.model_moderation' => false,
            'referrals.providers.opentable.value' => null, 'referrals.providers.resy.value' => null,
        ]);
    }

    protected function staff(string $role): User
    {
        return User::factory()->create(['is_staff' => true, 'roles' => [$role]]);
    }

    public function test_reserve_redirects_only_to_the_stored_link_and_counts_clicks_per_day(): void
    {
        $place = Place::create(['name' => 'Le Diplomate', 'stop' => 'E03', 'reservation_url' => 'https://www.opentable.com/r/le-diplomate']);

        // Whatever the request says, people go to the stored link.
        $this->get(route('places.reserve', $place).'?url=https://evil.example/phish&to=https://evil.example')
            ->assertRedirect('https://www.opentable.com/r/le-diplomate');
        $this->get(route('places.reserve', $place))->assertRedirect('https://www.opentable.com/r/le-diplomate');

        $row = DB::table('referral_clicks')->sole();
        $this->assertSame([$place->id, 0, 'opentable', 2, now(config('stoop.timezone'))->toDateString()], [(int) $row->place_id, (int) $row->dispatch_id, $row->provider, (int) $row->clicks, $row->day]);

        // A new DC day is a new row.
        $this->travel(1)->days();
        $this->get(route('places.reserve', $place));
        $this->assertSame(2, DB::table('referral_clicks')->count());

        // No link, no redirect; and a stored link that isn't http(s) never goes out.
        $none = Place::create(['name' => 'No Booking']);
        $this->get(route('places.reserve', $none))->assertNotFound();
        $bad = Place::create(['name' => 'Bad Link']);
        $bad->forceFill(['reservation_url' => 'javascript:alert(1)'])->save();
        $this->get(route('places.reserve', $bad))->assertNotFound();
    }

    public function test_partner_parameters_are_added_only_when_configured(): void
    {
        $place = Place::create(['name' => 'Rose’s', 'reservation_url' => 'https://resy.com/cities/dc/roses?date=2026-11-01#top']);
        $this->get(route('places.reserve', $place))->assertRedirect('https://resy.com/cities/dc/roses?date=2026-11-01#top');

        config(['referrals.providers.resy.value' => 'stoop123']);
        $this->get(route('places.reserve', $place))->assertRedirect('https://resy.com/cities/dc/roses?date=2026-11-01&aff_id=stoop123#top');

        // The restaurant's own site never gets a partner parameter, and a look-alike host isn't Resy.
        $this->assertSame('website', Referrals::provider('https://roses-resy.com.example.org/book'));
        $this->assertSame('website', Referrals::provider('https://notresy.com/x'));
        $this->assertSame('resy', Referrals::provider('https://widgets.resy.com/x'));
    }

    public function test_commission_label_and_sponsored_rel_only_with_an_affiliate_link(): void
    {
        $place = Place::create(['name' => 'Le Diplomate', 'reservation_url' => 'https://www.opentable.com/r/le-diplomate']);

        $this->get(route('places.show', $place))->assertOk()
            ->assertSee('Reserve')->assertSee('rel="nofollow noopener"', false)
            ->assertDontSee(Referrals::COMMISSION)->assertDontSee('rel="sponsored noopener"', false);

        config(['referrals.providers.opentable.value' => 'partner-42']);
        $this->get(route('places.show', $place))->assertOk()
            ->assertSee(Referrals::COMMISSION)->assertSee('rel="sponsored noopener"', false);

        // Ticket links: the same rule.
        $host = User::factory()->create();
        $happening = Dispatch::factory()->for($host)->create(['type' => 'happening', 'line' => 'orange']);
        $happening->happening()->create(['ticket_url' => 'https://www.eventbrite.com/e/123', 'starts_at' => now()->addDay()]);
        $this->get(route('dispatches.show', $happening))->assertSee('Get tickets')->assertDontSee(Referrals::COMMISSION);
        config(['referrals.providers.eventbrite.value' => 'aff9']);
        $this->get(route('dispatches.show', $happening))->assertSee(Referrals::COMMISSION);

        $this->get(route('happenings.tickets', $happening))->assertRedirect('https://www.eventbrite.com/e/123?aff=aff9');
        $this->assertSame(1, (int) DB::table('referral_clicks')->where(['dispatch_id' => $happening->id, 'provider' => 'eventbrite'])->value('clicks'));
        $this->assertSame(1, $happening->happening->fresh()->ticket_clicks);
    }

    public function test_owners_and_editors_set_reservation_links(): void
    {
        $owner = User::factory()->create();
        $place = Place::create(['name' => 'Owned', 'owner_id' => $owner->id]);

        Livewire::actingAs(User::factory()->create())->test(PlaceReservation::class, ['placeId' => $place->id])->call('edit')->assertForbidden();

        Livewire::actingAs($owner)->test(PlaceReservation::class, ['placeId' => $place->id])
            ->call('edit')->set('url', 'not a link')->call('save')->assertHasErrors('url')
            ->set('url', 'https://www.exploretock.com/owned')->call('save')->assertHasNoErrors();
        $this->assertSame('https://www.exploretock.com/owned', $place->fresh()->reservation_url);

        // Editors can edit places now, but importing, deleting and claims stay with sales.
        $editor = $this->staff('editor');
        Livewire::actingAs($editor)->test(PlacesPage::class)
            ->call('edit', $place->id)->set('form.reservation_url', 'https://resy.com/owned')->call('save')->assertHasNoErrors();
        $this->assertSame('https://resy.com/owned', $place->fresh()->reservation_url);
        Livewire::actingAs($editor)->test(PlacesPage::class)->call('delete', $place->id)->assertForbidden();

        $this->actingAs($this->staff('moderator'))->get(route('admin.places'))->assertForbidden();
    }

    public function test_eats_adds_a_reserve_link(): void
    {
        config(['referrals.providers.opentable.value' => 'p1']);
        $place = Place::create(['name' => 'Dukem', 'category' => 'Restaurant', 'stop' => 'E03', 'blurb' => 'Ethiopian platters.', 'list_name' => 'Best Ethiopian', 'list_rank' => 1, 'reservation_url' => 'https://www.opentable.com/r/dukem']);

        $dispatch = Dispatch::factory()->create(['body' => '@eats Ethiopian near U St']);
        (new RunBots($dispatch))->handle();

        $this->assertStringContainsString('Reserve: '.route('places.reserve', $place).' ('.Referrals::COMMISSION.')', $dispatch->replies()->first()->body);
    }

    public function test_sales_report_and_csv(): void
    {
        $place = Place::create(['name' => 'Le Diplomate', 'reservation_url' => 'https://www.opentable.com/r/le-diplomate']);
        $this->get(route('places.reserve', $place));
        $this->get(route('places.reserve', $place));

        $sales = $this->staff('sales');
        Livewire::actingAs($sales)->test(ReferralsPage::class)->assertSee('OpenTable')->assertSee('Le Diplomate');

        $csv = $this->actingAs($sales)->get(route('admin.referrals.csv'))->assertOk()->getContent();
        $this->assertStringContainsString(now(config('stoop.timezone'))->toDateString().',opentable,2', $csv);

        $editor = $this->staff('editor');
        $this->actingAs($editor)->get(route('admin.referrals'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.referrals.csv'))->assertForbidden();
    }
}
