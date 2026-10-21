<?php

namespace Tests\Feature;

use App\Livewire\Admin\Ads as AdsPage;
use App\Livewire\Admin\Business as BusinessAdmin;
use App\Livewire\Admin\PromotionQueue;
use App\Livewire\Pages\BusinessAnalytics;
use App\Livewire\Pages\Feed;
use App\Livewire\Pages\PlaceShow;
use App\Livewire\Pages\Promote;
use App\Livewire\Settings\Business as BusinessSettings;
use App\Models\BusinessAccount;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\PlaceDeal;
use App\Models\Promotion;
use App\Models\SponsoredDispatch;
use App\Models\User;
use App\Support\Business;
use App\Support\Promotions;
use App\Support\Stripe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    protected const WEBHOOK_SECRET = 'whsec_test_123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'stoop.model_moderation' => false, 'services.openai.key' => null, 'services.anthropic.key' => null,
            'business.stripe.key' => 'pk_test_x', 'business.stripe.secret' => 'sk_test_x', 'business.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'business.plans.business.stripe_price' => 'price_business_test',
        ]);
        Http::preventStrayRequests();
    }

    protected function owner(): array
    {
        $owner = User::factory()->create();

        return [$owner, Place::create(['name' => "Ben's Chili Bowl", 'stop' => 'E03', 'owner_id' => $owner->id])];
    }

    /** Post a signed webhook the way Stripe does. */
    protected function webhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', "$t.$payload", $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$sig", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    protected function subscribe(User $user): void
    {
        $this->webhook(['id' => 'evt_sub_'.$user->id, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_'.$user->id, 'subscription' => 'sub_'.$user->id,
            'client_reference_id' => (string) $user->id, 'metadata' => ['user_id' => (string) $user->id, 'kind' => 'business'],
        ]]])->assertOk();
        Once::flush();
    }

    protected function deal(Place $place, string $title): PlaceDeal
    {
        return PlaceDeal::post($place->owner, $place, ['title' => $title, 'ends_at' => now('America/New_York')->addDays(3)->format('Y-m-d H:i')]);
    }

    public function test_without_stripe_keys_upgrade_and_promote_are_hidden_and_admins_see_a_note(): void
    {
        config(['business.stripe.secret' => null]);
        [$owner, $place] = $this->owner();
        $happening = Dispatch::factory()->create(['user_id' => $owner->id, 'type' => 'happening']);

        $this->assertFalse(Stripe::configured());
        Livewire::actingAs($owner)->test(BusinessSettings::class)->assertDontSee('Upgrade to Business')->assertDontSee('Manage billing');
        Livewire::actingAs($owner)->test(PlaceShow::class, ['place' => $place])->assertDontSee('Upgrade to Business');
        $this->assertNull(Promotions::kindFor($owner, $happening));
        $this->actingAs($owner)->get(route('promote', ['dispatch', $happening->id]))->assertForbidden();
        $this->actingAs($owner)->post(route('business.upgrade'))->assertStatus(503);
        $this->webhook(['id' => 'evt_1', 'type' => 'invoice.payment_failed', 'data' => ['object' => []]])->assertNotFound();

        $sales = User::factory()->create(['is_staff' => true, 'roles' => ['sales']]);
        Livewire::actingAs($sales)->test(BusinessAdmin::class)->assertSee('Stripe not configured');
        Livewire::actingAs($sales)->test(AdsPage::class)->assertSee('Stripe not configured');

        // Live keys are refused outright: test mode only.
        config(['business.stripe.secret' => 'sk_live_real']);
        $this->assertFalse(Stripe::configured());
        Http::assertNothingSent();
    }

    public function test_upgrade_creates_a_subscription_checkout_session(): void
    {
        [$owner] = $this->owner();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

        Livewire::actingAs($owner)->test(BusinessSettings::class)->assertSee('Upgrade to Business');
        $this->actingAs($owner)->post(route('business.upgrade'))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r->hasHeader('Authorization', 'Bearer sk_test_x')
            && $r['mode'] === 'subscription'
            && $r['line_items'][0]['price'] === 'price_business_test'
            && $r['metadata']['user_id'] === (string) $owner->id
            && $r['subscription_data']['metadata']['user_id'] === (string) $owner->id
            && $r['client_reference_id'] === (string) $owner->id
            && str_contains($r['success_url'], '/settings/business'));
    }

    public function test_webhooks_check_the_signature_and_run_once_per_event(): void
    {
        [$owner] = $this->owner();
        $failed = ['id' => 'evt_fail_1', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['customer' => 'cus_'.$owner->id]]];

        $this->webhook($failed, 'whsec_wrong')->assertStatus(400);
        $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($failed))->assertStatus(400);

        $this->subscribe($owner);
        $this->webhook($failed)->assertOk()->assertSee('ok');
        $this->webhook($failed)->assertOk()->assertSee('duplicate');
        $this->assertSame(1, BusinessAccount::where('user_id', $owner->id)->value('failed_payments'));
        $this->assertSame(2, DB::table('stripe_events')->count());
    }

    public function test_subscribing_raises_limits_and_adds_the_badge_and_canceling_takes_them_away(): void
    {
        [$owner, $place] = $this->owner();
        $post = Dispatch::factory()->create(['user_id' => $owner->id, 'place_id' => $place->id, 'as_business' => true, 'stop' => 'E03', 'line' => 'red']);

        $this->deal($place, 'Half-smokes $5');
        $this->assertSame(1, Business::dealLimit($place));
        $this->assertFalse(Business::verified($place));
        Livewire::test(PlaceShow::class, ['place' => $place])->assertDontSee('Verified business');

        $this->subscribe($owner);
        $this->assertTrue(Business::isBusiness($owner));
        $this->assertSame(3, Business::dealLimit($place->fresh()));
        $this->deal($place->fresh(), 'Chili fries $3');
        $this->deal($place->fresh(), 'Milkshakes $2');
        Livewire::test(PlaceShow::class, ['place' => $place])->assertSee('Verified business');
        Livewire::test(Feed::class, ['tab' => 'latest'])->assertSee('VERIFIED BUSINESS');
        $this->assertTrue(Dispatch::whereKey($post->id)->exists());

        // Stripe updates the period, then the owner cancels in the Billing Portal.
        $this->webhook(['id' => 'evt_upd', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_'.$owner->id, 'customer' => 'cus_'.$owner->id, 'status' => 'active', 'cancel_at_period_end' => true,
            'items' => ['data' => [['current_period_end' => now()->addMonth()->timestamp]]],
        ]]])->assertOk();
        $this->assertTrue(BusinessAccount::first()->cancel_at_period_end);
        $this->assertNotNull(BusinessAccount::first()->current_period_end);

        $this->webhook(['id' => 'evt_del', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_'.$owner->id, 'customer' => 'cus_'.$owner->id, 'status' => 'canceled']]])->assertOk();
        Once::flush();
        $this->assertFalse(Business::isBusiness($owner));
        $this->assertSame(1, Business::dealLimit($place->fresh()));
        $this->expectException(ValidationException::class);
        $this->deal($place->fresh(), 'A fourth deal');
    }

    public function test_free_owners_manage_one_place_and_business_owners_several(): void
    {
        [$owner] = $this->owner();
        $second = Place::create(['name' => 'Florida Ave Grill', 'stop' => 'E03']);

        Livewire::actingAs($owner)->test(PlaceShow::class, ['place' => $second])
            ->set('claimRole', 'Owner')->set('claimEvidence', 'Call the restaurant line')->call('claim')->assertHasErrors('claimEvidence');

        $this->subscribe($owner);
        Livewire::actingAs($owner)->test(PlaceShow::class, ['place' => $second])
            ->set('claimRole', 'Owner')->set('claimEvidence', 'Call the restaurant line')->call('claim')->assertHasNoErrors();
        $this->assertSame(1, $second->claims()->count());
    }

    public function test_a_paid_promotion_goes_through_checkout_review_and_runs_as_a_promoted_ad(): void
    {
        [$owner, $place] = $this->owner();
        $this->subscribe($owner);
        $deal = $this->deal($place, 'Half-smokes $5 before 6');
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_promo_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_promo_1'])]);

        Livewire::actingAs($owner)->test(Promote::class, ['kind' => 'deal', 'id' => $deal->id])
            ->set('form.stops', ['E03'])->set('form.lines', ['red'])->set('form.tier', 'medium')->call('pay')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_promo_1');
        $promo = Promotion::sole();
        $this->assertSame('unpaid', $promo->status);
        Http::assertSent(fn (Request $r) => $r['mode'] === 'payment' && $r['line_items'][0]['price_data']['unit_amount'] === '5000'
            && $r['metadata']['promotion_id'] === (string) $promo->id);

        // Unpaid promotions aren't in the queue; the webhook moves it to review.
        $sales = User::factory()->create(['is_staff' => true, 'roles' => ['sales']]);
        Livewire::actingAs($sales)->test(PromotionQueue::class)->assertSee('Nothing waiting');
        $this->webhook(['id' => 'evt_paid', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_promo_1', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_123', 'metadata' => ['promotion_id' => (string) $promo->id],
        ]]])->assertOk();
        $this->assertSame('review', $promo->fresh()->status);

        Livewire::actingAs($sales)->test(PromotionQueue::class)->assertSee('Half-smokes $5 before 6')->call('approve', $promo->id)->call('approve', $promo->id);
        $ad = SponsoredDispatch::sole();
        $this->assertTrue((bool) $ad->promoted);
        $this->assertSame("Ben's Chili Bowl", $ad->advertiser);
        $this->assertSame(5000, $ad->goal);
        $this->assertSame(['E03'], $ad->stops);
        $this->assertSame(route('places.show', $place), $ad->url);
        $this->assertSame($ad->id, $promo->fresh()->sponsored_dispatch_id);

        // It shows in the stop's feed with the promoted label and the usual ad menu.
        Dispatch::factory()->count(10)->create(['line' => 'red', 'stop' => 'E03']);
        $html = Livewire::actingAs(User::factory()->create())->test(Feed::class, ['tab' => 'latest', 'stop' => 'E03'])->html();
        $this->assertStringContainsString('Promoted · Ben', $html);
        $this->assertStringContainsString('Why am I seeing this?', $html);
        Livewire::actingAs($owner)->test(BusinessAnalytics::class, ['place' => $place])->assertSee('Promotions')->assertSee('1 / 5,000');
    }

    public function test_rejecting_a_paid_promotion_refunds_it_through_stripe(): void
    {
        $host = User::factory()->create();
        $happening = Dispatch::factory()->create(['user_id' => $host->id, 'type' => 'happening']);
        $promo = Promotion::create(['user_id' => $host->id, 'kind' => 'happening', 'target_id' => $happening->id, 'name' => $host->name, 'starts_on' => today(), 'ends_on' => today()->addDays(3),
            'tier' => 'small', 'amount_cents' => 2500, 'impressions' => 2000, 'status' => 'review', 'payment_intent' => 'pi_999']);
        $mod = User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);

        // A failed refund leaves it in the queue.
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()->push(['error' => ['message' => 'Try again']], 500)->push(['id' => 're_1', 'object' => 'refund'])]);
        Livewire::actingAs($mod)->test(PromotionQueue::class)->call('reject', $promo->id);
        $this->assertSame('review', $promo->fresh()->status);

        Livewire::actingAs($mod)->test(PromotionQueue::class)->set("reasons.{$promo->id}", 'Not a local event')->call('reject', $promo->id);
        $this->assertSame('rejected', $promo->fresh()->status);
        $this->assertNotNull($promo->fresh()->refunded_at);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/refunds' && $r['payment_intent'] === 'pi_999');
        $this->assertSame(0, SponsoredDispatch::count());
        $this->assertStringContainsString('refunded', $host->notifications()->first()->data['message']);
    }

    public function test_business_accounts_get_one_free_promoted_happening_a_month(): void
    {
        [$owner] = $this->owner();
        $this->subscribe($owner);
        [$first, $second] = Dispatch::factory()->count(2)->create(['user_id' => $owner->id, 'type' => 'happening']);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_2'])]);

        Livewire::actingAs($owner)->test(Promote::class, ['kind' => 'dispatch', 'id' => $first->id])->assertSee('This one’s free')->call('pay')->assertRedirect(route('business.edit', ['promoted' => 'free']));
        $this->assertTrue(Promotion::where('target_id', $first->id)->value('free'));
        $this->assertSame('review', Promotion::where('target_id', $first->id)->value('status'));
        Http::assertNothingSent();

        Livewire::actingAs($owner)->test(Promote::class, ['kind' => 'dispatch', 'id' => $second->id])->assertSee('Pay with Stripe')->call('pay')->assertRedirect('https://checkout.stripe.com/c/pay/cs_2');
        $this->assertFalse(Promotion::where('target_id', $second->id)->value('free'));
    }

    public function test_promotions_need_the_post_to_pass_moderation(): void
    {
        $host = User::factory()->create();
        $happening = Dispatch::factory()->create(['user_id' => $host->id, 'type' => 'happening']);
        $promo = Promotion::create(['user_id' => $host->id, 'kind' => 'happening', 'target_id' => $happening->id, 'name' => $host->name, 'starts_on' => today(), 'ends_on' => today()->addDays(3),
            'tier' => 'small', 'amount_cents' => 0, 'impressions' => 2000, 'free' => true, 'status' => 'review']);
        $happening->update(['status' => 'held']);

        $sales = User::factory()->create(['is_staff' => true, 'roles' => ['sales']]);
        Livewire::actingAs($sales)->test(PromotionQueue::class)->assertSee('hasn’t passed moderation')->call('approve', $promo->id);
        $this->assertSame('review', $promo->fresh()->status);
        $this->assertSame(0, SponsoredDispatch::count());

        // Free ones are rejected without any Stripe call.
        Livewire::actingAs($sales)->test(PromotionQueue::class)->call('reject', $promo->id);
        $this->assertSame('rejected', $promo->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_role_gates(): void
    {
        [$owner, $place] = $this->owner();
        $stranger = User::factory()->create();
        $editor = User::factory()->create(['is_staff' => true, 'roles' => ['editor']]);
        $mod = User::factory()->create(['is_staff' => true, 'roles' => ['moderator']]);
        $sales = User::factory()->create(['is_staff' => true, 'roles' => ['sales']]);

        Livewire::actingAs($sales)->test(PromotionQueue::class)->assertOk();
        Livewire::actingAs($mod)->test(PromotionQueue::class)->assertOk();
        Livewire::actingAs($editor)->test(PromotionQueue::class)->assertForbidden();
        Livewire::actingAs($stranger)->test(PromotionQueue::class)->assertForbidden();

        $this->actingAs($sales)->get(route('admin.business'))->assertOk()->assertSee('MRR');
        $this->actingAs($mod)->get(route('admin.business'))->assertForbidden();
        $this->actingAs($stranger)->get(route('admin.business'))->assertForbidden();

        // Analytics are the owner's; promoting a dispatch needs Business, a happening only a verified host.
        $this->actingAs($stranger)->get(route('business.analytics', $place))->assertForbidden();
        Livewire::actingAs($owner)->test(BusinessAnalytics::class, ['place' => $place])->assertSee('Analytics come with the Business plan');
        $post = Dispatch::factory()->create(['user_id' => $stranger->id, 'type' => 'dispatch']);
        $happening = Dispatch::factory()->create(['user_id' => $stranger->id, 'type' => 'happening']);
        $this->assertNull(Promotions::kindFor($stranger, $post));
        $this->assertSame('happening', Promotions::kindFor($stranger, $happening));
        $this->assertNull(Promotions::kindFor($owner, $happening), 'Only your own posts');
        $this->assertNull(Promotions::kindFor($stranger->forceFill(['email_verified_at' => null]), $happening));
        $this->actingAs($owner)->get(route('promote', ['dispatch', $happening->id]))->assertForbidden();
    }

    public function test_owners_see_views_deal_views_and_clicks_by_day(): void
    {
        [$owner, $place] = $this->owner();
        $this->subscribe($owner);
        $deal = $this->deal($place, 'Half-smokes $5');

        Livewire::actingAs(User::factory()->create())->test(PlaceShow::class, ['place' => $place]);
        Livewire::test(Feed::class, ['stop' => 'E03'])->assertSee(route('deals.open', $deal));
        $this->get(route('deals.open', $deal))->assertRedirect(route('places.show', $place));
        $this->get(route('deals.open', $deal));

        $row = DB::table('place_daily')->where('place_id', $place->id)->first();
        $this->assertSame([1, 1, 1], [(int) $row->views, (int) $row->deal_views, (int) $row->deal_clicks], 'Once per person per day');
        Livewire::actingAs($owner)->test(BusinessAnalytics::class, ['place' => $place])->assertSee('Page views')->assertSee('Deal clicks');
    }
}
