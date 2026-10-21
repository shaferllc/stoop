<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\PlaceDeal;
use App\Models\Promotion;
use App\Models\SponsoredDispatch;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-serve promoted posts. A Business owner can boost their deal, happening or dispatch; any
 * verified person can boost their own Happening. They pay once with Stripe Checkout, staff
 * approve it, and it runs through the Ads system as "Promoted · name". Rejections are refunded.
 */
class Promotions
{
    /**
     * Whether $user may promote this deal or dispatch, and as what kind.
     */
    public static function kindFor(User $user, PlaceDeal|Dispatch $target): ?string
    {
        if (! Stripe::configured() || ! $user->hasVerifiedEmail()) {
            return null;
        }
        if ($target instanceof PlaceDeal) {
            return $target->place->owner_id === $user->id && Business::isBusiness($user) && $target->ends_at->isFuture() ? 'deal' : null;
        }
        if ($target->user_id !== $user->id || $target->parent_id || $target->status !== 'approved') {
            return null;
        }

        return match (true) {
            $target->type === 'happening' => 'happening',
            Business::isBusiness($user) => 'dispatch',
            default => null,
        };
    }

    /**
     * A Business account gets one free promoted happening per month (DC time). Rejected ones don't use it up.
     */
    public static function freeLeft(User $user): bool
    {
        $month = now(config('stoop.timezone'))->startOfMonth();

        return Business::isBusiness($user) && Promotion::where('user_id', $user->id)->where('free', true)
            ->whereIn('status', ['review', 'approved'])->where('created_at', '>=', $month->utc())->count() < Business::plan($user)['free_promotions'];
    }

    /**
     * Start a promotion. Returns the Stripe Checkout URL, or null when the free monthly
     * happening covered it and it went straight to review.
     *
     * @param  array<string, mixed>  $input  stops, lines, starts_on, ends_on, tier
     *
     * @throws ValidationException
     */
    public static function start(User $user, PlaceDeal|Dispatch $target, array $input): ?string
    {
        abort_unless($kind = self::kindFor($user, $target), 403);
        $tz = config('stoop.timezone');
        $today = now($tz)->toDateString();
        $data = Validator::make($input, [
            'stops' => ['array', 'max:10'],
            'stops.*' => [Rule::in(array_keys(config('stoop.stations')))],
            'lines' => ['array'],
            'lines.*' => [Rule::in(array_keys(config('stoop.lines')))],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.now($tz)->addDays(60)->toDateString()],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'tier' => ['required', Rule::in(array_keys(config('business.promotion_tiers')))],
        ], [], ['starts_on' => 'start', 'ends_on' => 'end'])->validate();
        if (CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) >= config('business.promotion_max_days')) {
            throw ValidationException::withMessages(['ends_on' => 'Promotions run up to '.config('business.promotion_max_days').' days.']);
        }

        $free = $kind === 'happening' && self::freeLeft($user);
        $tier = $free ? config('business.free_promotion_tier') : $data['tier'];
        $place = $target instanceof PlaceDeal ? $target->place : ($target->as_business ? $target->place : null);
        $promotion = Promotion::create([
            'user_id' => $user->id, 'place_id' => $place?->id, 'kind' => $kind, 'target_id' => $target->id,
            'name' => $place?->name ?? $user->name,
            'stops' => array_values($data['stops'] ?? []) ?: null, 'lines' => array_values($data['lines'] ?? []) ?: null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'tier' => $tier,
            'amount_cents' => $free ? 0 : config("business.promotion_tiers.$tier.cents"),
            'impressions' => config("business.promotion_tiers.$tier.impressions"),
            'free' => $free, 'status' => $free ? 'review' : 'unpaid',
        ]);
        if ($free) {
            return null;
        }

        $session = Stripe::client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $promotion->amount_cents,
                'product_data' => ['name' => 'Stoop promoted '.$kind.': '.number_format($promotion->impressions).' impressions']]]],
            'client_reference_id' => (string) $user->id,
            'metadata' => ['promotion_id' => (string) $promotion->id],
            'payment_intent_data' => ['metadata' => ['promotion_id' => (string) $promotion->id]],
            'success_url' => route('business.edit', ['promoted' => $promotion->id]),
            'cancel_url' => route('business.edit'),
        ]);
        $promotion->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    /**
     * Why staff can't approve this yet (the post was removed, the deal ended), or null.
     */
    public static function blocker(Promotion $promotion): ?string
    {
        $target = $promotion->target();

        return match (true) {
            ! $target => 'The post is gone.',
            $target instanceof PlaceDeal => $target->ends_at->isPast() ? 'The deal has ended.' : null,
            $target->status !== 'approved' => 'The post hasn’t passed moderation ('.$target->status.').',
            default => null,
        };
    }

    /**
     * Approve: the promotion runs as a promoted sponsored dispatch linking to the original post.
     */
    public static function approve(Promotion $promotion, User $staff): SponsoredDispatch
    {
        abort_if($reason = self::blocker($promotion), 422, $reason ?? '');

        return DB::transaction(function () use ($promotion, $staff) {
            // Conditional, so a double click can't make two ads.
            abort_unless(Promotion::whereKey($promotion->id)->where('status', 'review')->update(['status' => 'approved', 'reviewed_by' => $staff->id, 'reviewed_at' => now()]), 409);
            $target = $promotion->target();
            $tz = config('stoop.timezone');
            $ad = SponsoredDispatch::create([
                'advertiser' => $promotion->name,
                'body' => str($target instanceof PlaceDeal ? trim($target->title."\n".$target->details) : $target->body)->limit(497)->toString(),
                'url' => $target instanceof PlaceDeal ? route('places.show', $target->place) : route('dispatches.show', $target),
                'stops' => $promotion->stops, 'lines' => $promotion->lines,
                'starts_at' => max(CarbonImmutable::parse($promotion->starts_on->toDateString(), $tz)->utc(), now()->toImmutable()),
                'ends_at' => CarbonImmutable::parse($promotion->ends_on->toDateString(), $tz)->endOfDay()->utc(),
                'daily_cap' => 3, 'goal' => $promotion->impressions, 'created_by' => $staff->id,
            ]);
            $ad->forceFill(['promoted' => true])->save();
            $promotion->update(['sponsored_dispatch_id' => $ad->id]);
            Audit::log('promotion.approved', $promotion);
            $promotion->user->notify(new ModerationNotice('Your promotion was approved and starts '.$promotion->starts_on->format('M j').'.'));

            return $ad;
        });
    }

    /**
     * Reject and refund. The refund goes first: if Stripe fails, the promotion stays in the queue.
     */
    public static function reject(Promotion $promotion, User $staff, ?string $reason = null): void
    {
        abort_unless($promotion->status === 'review', 409);
        if (! $promotion->free && $promotion->payment_intent) {
            Stripe::client()->refunds->create(['payment_intent' => $promotion->payment_intent], ['idempotency_key' => 'promotion-refund-'.$promotion->id]);
            $promotion->refunded_at = now();
        }
        $promotion->fill(['status' => 'rejected', 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'reject_reason' => $reason ? str($reason)->limit(197)->toString() : null])->save();
        Audit::log('promotion.rejected', $promotion, ['reason' => $reason]);
        $promotion->user->notify(new ModerationNotice('Your promotion wasn’t approved'.($reason ? ': '.$reason : '').'.'.($promotion->refunded_at ? ' We refunded your payment.' : '')));
    }

    /**
     * Shown for delivery: impressions and clicks from its ad.
     *
     * @return array{impressions: int, clicks: int, ctr: ?float}
     */
    public static function delivery(Promotion $promotion): array
    {
        $ad = $promotion->ad;

        return ['impressions' => (int) $ad?->impressions, 'clicks' => (int) $ad?->clicks, 'ctr' => $ad ? Ads::ctr($ad->clicks, $ad->impressions) : null];
    }
}
