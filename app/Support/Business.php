<?php

namespace App\Support;

use App\Models\BusinessAccount;
use App\Models\Place;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;

/**
 * Business accounts: the plan limits, Stripe Checkout and the Billing Portal, the webhooks that
 * keep the plan in step with Stripe, and the owner's place analytics.
 */
class Business
{
    /**
     * Owners with a Business plan right now. Once per request, for badges on every card.
     *
     * @return array<int, int>
     */
    public static function activeUserIds(): array
    {
        return once(fn () => BusinessAccount::active()->pluck('user_id')->all());
    }

    public static function isBusiness(?User $user): bool
    {
        return $user && in_array($user->id, self::activeUserIds(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(?User $user): array
    {
        return config('business.plans.'.(self::isBusiness($user) ? 'business' : 'free'));
    }

    /** A place shows "Verified business" while its owner is on the Business plan. */
    public static function verified(Place $place): bool
    {
        return $place->owner_id && in_array($place->owner_id, self::activeUserIds(), true);
    }

    public static function dealLimit(Place $place): int
    {
        return self::plan($place->owner)['deals'];
    }

    public static function canUpgrade(): bool
    {
        return Stripe::configured() && config('business.plans.business.stripe_price');
    }

    /**
     * A Stripe Checkout (subscription mode) URL for the Business plan.
     */
    public static function checkoutUrl(User $user): string
    {
        abort_unless(self::canUpgrade(), 503, 'Stripe not configured');
        abort_if(self::isBusiness($user), 422, 'You’re already on Business.');

        $meta = ['user_id' => (string) $user->id, 'kind' => 'business'];
        $customer = BusinessAccount::where('user_id', $user->id)->value('stripe_customer_id');

        return Stripe::client()->checkout->sessions->create(array_filter([
            'mode' => 'subscription',
            'line_items' => [['price' => config('business.plans.business.stripe_price'), 'quantity' => 1]],
            'customer' => $customer,
            'client_reference_id' => (string) $user->id,
            'metadata' => $meta,
            'subscription_data' => ['metadata' => $meta],
            'success_url' => route('business.edit', ['checkout' => 'done']),
            'cancel_url' => route('business.edit'),
        ]))->url;
    }

    /**
     * The Stripe Billing Portal, where owners change their card or cancel.
     */
    public static function portalUrl(User $user): string
    {
        $customer = BusinessAccount::where('user_id', $user->id)->value('stripe_customer_id');
        abort_unless(Stripe::configured() && $customer, 404);

        return Stripe::client()->billingPortal->sessions->create(['customer' => $customer, 'return_url' => route('business.edit')])->url;
    }

    /**
     * Handle one verified Stripe event once. The event id is recorded in the same transaction,
     * so a failure rolls it back and Stripe's retry runs it again.
     *
     * @param  array<string, mixed>  $event
     * @return bool False when the event was already handled.
     */
    public static function handle(array $event): bool
    {
        return DB::transaction(function () use ($event) {
            if (! DB::table('stripe_events')->insertOrIgnore(['id' => $event['id'], 'type' => $event['type'], 'created_at' => now()])) {
                return false;
            }
            $object = $event['data']['object'] ?? [];

            match ($event['type']) {
                'checkout.session.completed' => self::checkoutCompleted($object),
                'customer.subscription.updated', 'customer.subscription.deleted' => self::subscriptionChanged($object, $event['type'] === 'customer.subscription.deleted'),
                'invoice.payment_failed' => self::paymentFailed($object),
                default => null,
            };
            Once::flush();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected static function checkoutCompleted(array $session): void
    {
        if ($id = $session['metadata']['promotion_id'] ?? null) {
            if (($session['payment_status'] ?? null) === 'paid') {
                Promotion::whereKey($id)->where('status', 'unpaid')->where('stripe_session_id', $session['id'] ?? null)
                    ->update(['status' => 'review', 'payment_intent' => $session['payment_intent'] ?? null]);
            }

            return;
        }

        if (($session['mode'] ?? null) === 'subscription' && ($userId = $session['metadata']['user_id'] ?? $session['client_reference_id'] ?? null) && User::whereKey($userId)->exists()) {
            $account = BusinessAccount::firstOrNew(['user_id' => $userId]);
            $account->fill(['stripe_customer_id' => $session['customer'] ?? $account->stripe_customer_id, 'stripe_subscription_id' => $session['subscription'] ?? $account->stripe_subscription_id]);
            // The subscription event may already have set the real status.
            if (! $account->isActive()) {
                $account->status = 'active';
            }
            $account->save();
            Audit::log('business.subscribed', $account->user);
        }
    }

    /**
     * @param  array<string, mixed>  $sub
     */
    protected static function subscriptionChanged(array $sub, bool $deleted): void
    {
        $account = BusinessAccount::where('stripe_subscription_id', $sub['id'] ?? '')->first()
            ?? (($userId = $sub['metadata']['user_id'] ?? null) && User::whereKey($userId)->exists() ? BusinessAccount::firstOrNew(['user_id' => $userId]) : null);
        if (! $account) {
            return;
        }
        // Newer API versions keep the period end on the subscription item.
        $periodEnd = $sub['current_period_end'] ?? $sub['items']['data'][0]['current_period_end'] ?? null;
        $account->fill([
            'stripe_customer_id' => $sub['customer'] ?? $account->stripe_customer_id,
            'stripe_subscription_id' => $sub['id'] ?? $account->stripe_subscription_id,
            'status' => $deleted ? 'canceled' : (string) ($sub['status'] ?? 'incomplete'),
            'current_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'cancel_at_period_end' => (bool) ($sub['cancel_at_period_end'] ?? false),
        ])->save();
        if ($deleted) {
            Audit::log('business.canceled', $account->user);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected static function paymentFailed(array $invoice): void
    {
        if ($account = BusinessAccount::where('stripe_customer_id', $invoice['customer'] ?? '')->first()) {
            $account->forceFill(['failed_payments' => $account->failed_payments + 1, 'payment_failed_at' => now()])->save();
        }
    }

    /**
     * Monthly recurring revenue in cents: active Business accounts times the plan price.
     */
    public static function mrrCents(): int
    {
        return BusinessAccount::active()->count() * config('business.plans.business.price_cents');
    }

    /**
     * Count a page view, deal view or deal click once per viewer per day.
     */
    public static function count(int $placeId, string $metric): void
    {
        $day = now(config('stoop.timezone'))->toDateString();
        if (! Cache::add("biz:$metric:$placeId:".Ads::viewer(), 1, now()->addDay())) {
            return;
        }
        $row = ['place_id' => $placeId, 'day' => $day];
        DB::table('place_daily')->insertOrIgnore($row);
        DB::table('place_daily')->where($row)->increment($metric);
    }

    /**
     * The last $days days for a place: views, deal views and clicks, check-ins (Been here
     * marks on posts there) and Been here posts.
     *
     * @return Collection<int, object>
     */
    public static function analytics(Place $place, int $days = 30): Collection
    {
        $tz = config('stoop.timezone');
        $since = now($tz)->subDays($days - 1)->startOfDay();
        $daily = DB::table('place_daily')->where('place_id', $place->id)->where('day', '>=', $since->toDateString())->get()->keyBy('day');
        $byDay = fn ($rows) => $rows->countBy(fn ($at) => Carbon::parse($at)->timezone($tz)->toDateString());

        $checkins = $byDay(DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->where('dispatches.place_id', $place->id)->where('dispatches.status', 'approved')->whereNull('dispatches.deleted_at')
            ->where('marks.kind', 'been')->where('marks.created_at', '>=', $since->utc())->pluck('marks.created_at'));
        $been = $byDay(DB::table('dispatches')->where('place_id', $place->id)->where('type', 'been')->where('status', 'approved')
            ->whereNull('deleted_at')->where('created_at', '>=', $since->utc())->pluck('created_at'));

        return collect(range(0, $days - 1))->map(function ($i) use ($since, $daily, $checkins, $been) {
            $day = $since->copy()->addDays($i)->toDateString();

            return (object) ['day' => $day, 'views' => (int) ($daily[$day]->views ?? 0), 'deal_views' => (int) ($daily[$day]->deal_views ?? 0),
                'deal_clicks' => (int) ($daily[$day]->deal_clicks ?? 0), 'checkins' => $checkins[$day] ?? 0, 'been' => $been[$day] ?? 0];
        });
    }
}
