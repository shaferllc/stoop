<?php

namespace Tests\Feature;

use App\Livewire\Admin\Listings as ListingsPage;
use App\Livewire\Pages\Classifieds as ClassifiedsPage;
use App\Livewire\Pages\ListingShow;
use App\Models\Listing;
use App\Models\User;
use App\Support\Classifieds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClassifiedsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        config(['services.anthropic.key' => null, 'services.openai.key' => null, 'stoop.model_moderation' => false, 'classifieds.stripe_secret' => null]);
        Classifieds::fakeStripe(null);
    }

    protected function tearDown(): void
    {
        Classifieds::fakeStripe(null);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function postListing(array $input, ?User $user = null): array
    {
        return Classifieds::create($user ?? User::factory()->create(), [
            'category' => 'forsale', 'title' => 'Bike for sale', 'body' => 'A good city bike, barely ridden.', 'stop' => 'E03', 'price' => 120, ...$input,
        ]);
    }

    protected function errorsFor(array $input): array
    {
        try {
            $this->postListing($input);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public function test_without_stripe_paid_categories_are_coming_soon_and_free_ones_post_right_away(): void
    {
        $this->assertFalse(Classifieds::stripeReady());
        $this->assertFalse(Classifieds::canPost('jobs'));
        $this->assertTrue(Classifieds::canPost('forsale'));

        // A live-mode key isn't enough: this only runs in Stripe test mode.
        config(['classifieds.stripe_secret' => 'sk_live_abc']);
        $this->assertFalse(Classifieds::stripeReady());
        config(['classifieds.stripe_secret' => null]);

        $this->assertArrayHasKey('category', $this->errorsFor(['category' => 'housing', 'price' => 1800, 'title' => 'Sunny 1BR']));
        $this->assertSame(0, Listing::where('category', 'housing')->count());

        [$listing, $checkout] = $this->postListing([]);
        $this->assertNull($checkout);
        $this->assertTrue($listing->isLive());
        $this->assertSame(30, (int) round(now()->diffInDays($listing->expires_at)));

        $this->actingAs(User::factory()->create());
        Livewire::test(ClassifiedsPage::class)->call('startPosting')->set('posting', true)->assertSee('Coming soon')->assertSee('Bike for sale');
        $this->get(route('classifieds', ['category' => 'forsale', 'line' => 'green']))->assertOk()->assertSee('Bike for sale');
        $this->get(route('classifieds', ['line' => 'silver']))->assertOk()->assertDontSee('Bike for sale');
        $this->get(route('classifieds', ['max' => 100]))->assertOk()->assertDontSee('Bike for sale');
        $this->get(route('classifieds.show', $listing))->assertOk()->assertSee('A good city bike');
    }

    public function test_paid_listings_go_live_only_after_stripe_confirms_payment(): void
    {
        $sessions = [];
        Classifieds::fakeStripe(function (string $call, mixed $arg) use (&$sessions) {
            if ($call === 'create') {
                $id = 'cs_test_'.count($sessions);
                $sessions[$id] = ['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'payment_status' => 'unpaid', 'amount_total' => $arg['line_items'][0]['price_data']['unit_amount'], 'metadata' => $arg['metadata']];

                return $sessions[$id];
            }

            return $sessions[$arg];
        });

        $user = User::factory()->create();
        [$listing, $checkout] = $this->postListing(['category' => 'housing', 'title' => 'Sunny 1BR near U St', 'body' => 'Big windows, laundry in unit. Vouchers welcome.', 'price' => 1800], $user);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_0', $checkout);
        $this->assertSame('unpaid', $listing->status);
        $this->assertSame(1500, $sessions['cs_test_0']['amount_total']);

        // Coming back without paying, or with someone else's session, does nothing.
        $this->actingAs($user)->get(route('classifieds.paid', $listing).'?session_id=cs_test_0')->assertRedirect(route('classifieds.show', $listing));
        $this->assertSame('unpaid', $listing->fresh()->status);
        $this->actingAs($user)->get(route('classifieds.paid', $listing).'?session_id=cs_test_other');
        $this->assertSame('unpaid', $listing->fresh()->status);
        $this->actingAs(User::factory()->create())->get(route('classifieds.paid', $listing).'?session_id=cs_test_0')->assertForbidden();

        $sessions['cs_test_0']['payment_status'] = 'paid';
        $this->actingAs($user)->get(route('classifieds.paid', $listing).'?session_id=cs_test_0');
        $listing->refresh();
        $this->assertTrue($listing->isLive());
        $this->assertSame(1500, $listing->paid_cents);
        $this->assertNull($listing->checkout_session);

        // The same paid session can't be replayed for another 30 days.
        $expires = $listing->expires_at;
        $this->actingAs($user)->get(route('classifieds.paid', $listing).'?session_id=cs_test_0');
        $this->assertEquals($expires, $listing->fresh()->expires_at);
    }

    public function test_contact_info_is_rejected(): void
    {
        $this->assertArrayHasKey('body', $this->errorsFor(['body' => 'Call me at (202) 555-0142 for details.']));
        $this->assertArrayHasKey('body', $this->errorsFor(['body' => 'Email bikes@example.com to buy it.']));
        $this->assertArrayHasKey('body', $this->errorsFor(['title' => 'Text 202.555.0142']));
        $this->assertSame(0, Listing::count());
    }

    public function test_fair_housing_check_rejects_discriminatory_housing_ads(): void
    {
        config(['classifieds.categories.housing.price' => 0, 'classifieds.categories.roommates.price' => 0]);
        $housing = fn (string $body) => $this->errorsFor(['category' => 'housing', 'title' => 'Room in Petworth', 'body' => $body, 'price' => 1200]);

        foreach (['Nice place. No Section 8.', 'Quiet building, no kids please.', 'Christians only, no exceptions.', 'Vouchers not accepted here.', 'Adults only building.', 'Prefer white tenants.'] as $body) {
            $errors = $housing($body);
            $this->assertArrayHasKey('body', $errors, $body);
            $this->assertStringContainsString(config('classifieds.fair_housing_url'), $errors['body'][0]);
        }

        // Ordinary ads, and a roommate's gender preference (the shared-housing exception), are fine.
        $this->assertSame([], $housing('Sunny room, Section 8 vouchers welcome, kids welcome.'));
        $this->assertSame([], $this->errorsFor(['category' => 'roommates', 'title' => 'Female roommate wanted', 'body' => 'Looking for a female roommate for a 2BR in Shaw.', 'price' => 1100]));

        // For-sale ads aren't housing.
        $this->assertSame([], $this->errorsFor(['body' => 'Crib for sale, no kids left at home to use it.']));
    }

    public function test_job_listings_need_a_pay_range(): void
    {
        config(['classifieds.categories.jobs.price' => 0]);
        $job = ['category' => 'jobs', 'title' => 'Line cook', 'body' => 'Busy kitchen in Navy Yard is hiring.', 'price' => null];

        $errors = $this->errorsFor($job);
        $this->assertArrayHasKey('pay_min', $errors);
        $this->assertStringContainsString('pay transparency', $errors['pay_min'][0]);
        $this->assertArrayHasKey('pay_max', $this->errorsFor([...$job, 'pay_min' => 25, 'pay_max' => 20, 'pay_period' => 'hour']));
        $this->assertArrayHasKey('pay_min', $this->errorsFor([...$job, 'pay_min' => 0, 'pay_max' => 20, 'pay_period' => 'hour']));

        [$listing] = $this->postListing([...$job, 'pay_min' => 20, 'pay_max' => 26, 'pay_period' => 'hour']);
        $this->assertSame('$20–$26 an hour', $listing->priceLabel());
        $this->assertTrue($listing->isLive());
    }

    public function test_listings_expire_after_thirty_days_and_can_be_renewed_or_marked_sold(): void
    {
        $user = User::factory()->create();
        [$listing] = $this->postListing([], $user);

        $this->travel(31)->days();
        $this->assertFalse($listing->fresh()->isLive());
        $this->assertSame(0, Classifieds::board(null, null, null, null, null, null)->count());
        $this->get(route('classifieds.show', $listing))->assertNotFound();
        $this->actingAs($user)->get(route('classifieds.show', $listing))->assertOk()->assertSee('Expired');

        Livewire::actingAs($user)->test(ListingShow::class, ['listing' => $listing])->call('renew');
        $this->assertTrue($listing->fresh()->isLive());
        $this->assertSame(30, (int) round(now()->diffInDays($listing->fresh()->expires_at)));

        try {
            Classifieds::markFilled(User::factory()->create(), $listing);
            $this->fail('A stranger marked the listing filled.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Livewire::actingAs($user)->test(ListingShow::class, ['listing' => $listing])->call('markFilled');
        $this->assertSame('Sold', $listing->fresh()->statusLabel());
        $this->assertSame(0, Classifieds::board(null, null, null, null, null, null)->count());
    }

    public function test_screening_holds_and_reports_hide_listings(): void
    {
        $this->assertArrayHasKey('body', $this->errorsFor(['body' => 'This bike is the shit.']));

        DB::table('watch_phrases')->updateOrInsert(['phrase' => 'urban youth'], ['meaning' => 'Coded language about Black teenagers.', 'severity' => 'high', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$held] = $this->postListing(['body' => 'Great for urban youth in the area.']);
        $this->assertSame('held', $held->status);

        config(['services.openai.key' => 'test']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['results' => [['category_scores' => ['harassment' => 0.9]]]])
            ->push(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
        [$flagged] = $this->postListing(['title' => 'Couch for sale']);
        $this->assertSame('held', $flagged->status);
        $this->assertArrayHasKey('harassment', $flagged->screening['openai']);

        [$listing] = $this->postListing(['title' => 'Lamp for sale']);
        $this->assertSame('active', $listing->status);

        foreach (User::factory()->count(config('stoop.report_threshold'))->create() as $reporter) {
            Classifieds::report($reporter, $listing, 'scam');
        }
        $this->assertSame('held', $listing->fresh()->status);

        $moderator = User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);
        Livewire::actingAs($moderator)->test(ListingsPage::class)->assertSee('Lamp for sale')->call('approve', $listing->id);
        $this->assertTrue($listing->fresh()->isLive());
        $this->assertSame(0, $listing->reports()->whereNull('resolved_at')->count());

        $this->actingAs(User::factory()->create(['is_staff' => true, 'roles' => ['sales']]))->get(route('admin.listings'))->assertForbidden();
    }

    public function test_contact_goes_through_stoop_messages(): void
    {
        $seller = User::factory()->create(['message_requests' => true]);
        [$listing] = $this->postListing([], $seller);
        $buyer = User::factory()->create(['created_at' => now()->subWeek()]);

        $conversation = Classifieds::contact($buyer, $listing, 'Is it still available?');
        $this->assertStringContainsString('Bike for sale', $conversation->messages()->first()->body);
        $this->assertTrue($conversation->participants()->whereKey($seller->id)->exists());

        $this->expectException(ValidationException::class);
        Classifieds::contact($buyer, $listing, 'Call 202-555-0142');
    }

    public function test_app_api(): void
    {
        $this->postListing([]);
        Sanctum::actingAs(User::factory()->create(), ['app']);

        $this->getJson('/api/app/classifieds?category=forsale')->assertOk()
            ->assertJsonPath('data.0.title', 'Bike for sale')
            ->assertJsonPath('categories.0.open', false);

        $this->postJson('/api/app/classifieds', ['category' => 'jobs', 'title' => 'Barista', 'body' => 'Morning shifts in Shaw.', 'pay_min' => 18, 'pay_max' => 22, 'pay_period' => 'hour'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->postJson('/api/app/classifieds', ['category' => 'forsale', 'title' => 'Desk', 'body' => 'Solid oak desk, you haul.', 'price' => 50])
            ->assertCreated()->assertJsonPath('checkout_url', null)->assertJsonPath('data.status', 'live');
    }
}
