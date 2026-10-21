<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Dispatch;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;
use Throwable;

/**
 * Classifieds: jobs, apartments and rooms, roommates and for-sale items, priced per category
 * in config/classifieds.php. Paid listings go live after Stripe Checkout (test mode only);
 * free ones right away. Every listing is screened like a post (no swearing, no phone numbers
 * or emails, watch phrases, OpenAI), housing ads get a fair-housing check, and job ads need a
 * pay range (DC pay transparency). Contact is through Stoop messages only.
 */
class Classifieds
{
    protected static ?Closure $stripe = null;

    /**
     * @param  Closure(string, mixed): array<string, mixed>|null  $fake  ('create', params) or ('retrieve', session id)
     */
    public static function fakeStripe(?Closure $fake): void
    {
        self::$stripe = $fake;
    }

    /**
     * Stripe is on only with a test-mode key: this is not taking real money yet.
     */
    public static function stripeReady(): bool
    {
        return self::$stripe !== null || (bool) preg_match('/^(sk|rk)_test_/', (string) config('classifieds.stripe_secret'));
    }

    public static function price(string $category): int
    {
        return (int) config("classifieds.categories.$category.price", 0);
    }

    /**
     * Free categories always; paid ones once Stripe is set up ("Coming soon" until then).
     */
    public static function canPost(string $category): bool
    {
        return config("classifieds.categories.$category") !== null && (self::price($category) === 0 || self::stripeReady());
    }

    public static function priceLabel(string $category): string
    {
        $cents = self::price($category);

        return $cents === 0 ? 'Free' : '$'.rtrim(rtrim(number_format($cents / 100, 2), '0'), '.').' for '.config('classifieds.days').' days';
    }

    public static function breaksFairHousing(string $text): bool
    {
        return collect(config('classifieds.fair_housing_patterns'))->contains(fn ($pattern) => preg_match($pattern, $text));
    }

    /**
     * Check a new listing and save it. Free listings are screened and go live now; paid ones
     * wait as "unpaid" and the second value is the Stripe Checkout link to send people to.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Listing, 1: ?string}
     *
     * @throws ValidationException
     */
    public static function create(User $user, array $input): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $category = (string) ($input['category'] ?? '');
        $jobs = $category === 'jobs';

        if ($block = $user->postingBlock()) {
            $fail('body', $block);
        }

        $data = Validator::make($input, [
            'category' => ['required', Rule::in(array_keys(config('classifieds.categories')))],
            'title' => ['required', 'string', 'min:3', 'max:100'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'price' => ['nullable', Rule::prohibitedIf($jobs), 'integer', 'min:0', 'max:10000000'],
            'pay_min' => [Rule::requiredIf($jobs), 'nullable', Rule::prohibitedIf(! $jobs), 'integer', 'min:1', 'max:10000000'],
            'pay_max' => [Rule::requiredIf($jobs), 'nullable', Rule::prohibitedIf(! $jobs), 'integer', 'gte:pay_min', 'max:10000000'],
            'pay_period' => [Rule::requiredIf($jobs), 'nullable', Rule::prohibitedIf(! $jobs), Rule::in(array_keys(config('classifieds.pay_periods')))],
        ], [
            'pay_min.required' => 'Job listings need a pay range: DC’s pay transparency law requires one.',
            'pay_max.required' => 'Job listings need a pay range: DC’s pay transparency law requires one.',
            'pay_period.required' => 'Say whether the pay is hourly or yearly.',
        ], ['pay_min' => 'lowest pay', 'pay_max' => 'highest pay', 'pay_period' => 'pay period'])->validate();

        if (! self::canPost($category)) {
            $fail('category', config("classifieds.categories.$category.name").' listings are coming soon.');
        }

        $text = $data['title']."\n".$data['body'];
        if (Profanity::contains($text)) {
            $fail('body', 'Stoop keeps it clean: no swearing. Edit it and post again.');
        }
        if (LostAndFound::hasContactInfo($text)) {
            $fail('body', 'Leave out phone numbers and emails. Neighbors contact you through Stoop messages.');
        }
        if (config("classifieds.categories.$category.housing") && self::breaksFairHousing($text)) {
            $fail('body', 'Housing ads can’t turn people away for using a voucher, having kids, or their race, religion or national origin. See DC’s fair housing rules: '.config('classifieds.fair_housing_url'));
        }

        if (! RateLimiter::attempt('listings:'.$user->id, 10, fn () => true, 3600)) {
            $fail('body', 'That’s a lot of listings. Try again in an hour.');
        }

        $listing = Listing::create(['user_id' => $user->id, ...array_intersect_key($data, array_flip(['category', 'title', 'body', 'stop', 'price', 'pay_min', 'pay_max', 'pay_period'])), 'status' => 'unpaid']);

        if (self::price($category) === 0) {
            self::publish($listing, 0);

            return [$listing->fresh(), null];
        }

        return [$listing, self::checkout($listing)];
    }

    /**
     * A Stripe Checkout link for posting (or renewing) a paid listing.
     */
    public static function checkout(Listing $listing): string
    {
        abort_unless(self::stripeReady(), 422, 'Paid listings are coming soon.');

        $session = self::stripe('create', [
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => 'usd', 'unit_amount' => self::price($listing->category),
                'product_data' => ['name' => 'Stoop classified: '.$listing->categoryName().' ('.config('classifieds.days').' days)'],
            ]]],
            'client_reference_id' => (string) $listing->id,
            'metadata' => ['listing_id' => (string) $listing->id],
            'success_url' => route('classifieds.paid', $listing).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('classifieds.show', $listing),
        ]);
        $listing->forceFill(['checkout_session' => $session['id']])->save();

        return $session['url'];
    }

    /**
     * Back from Checkout: ask Stripe whether this listing's latest session is paid. Only then
     * does it go live (or get 30 more days). The link's query string alone proves nothing.
     */
    public static function confirm(Listing $listing, string $sessionId): bool
    {
        if (! $listing->checkout_session || ! hash_equals($listing->checkout_session, $sessionId)) {
            return false;
        }

        try {
            $session = self::stripe('retrieve', $sessionId);
        } catch (Throwable $e) {
            Log::warning('Stripe session check failed', ['listing' => $listing->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (($session['payment_status'] ?? null) !== 'paid' || (string) ($session['metadata']['listing_id'] ?? '') !== (string) $listing->id) {
            return false;
        }

        $listing->forceFill(['checkout_session' => null])->save();
        $listing->status === 'unpaid' ? self::publish($listing, (int) ($session['amount_total'] ?? 0)) : self::extend($listing, (int) ($session['amount_total'] ?? 0));

        return true;
    }

    /**
     * Screen a listing and put it live for 30 days, or hold it for a moderator. Watch phrases
     * and OpenAI can only hold a listing; a person decides what happens next.
     */
    public static function publish(Listing $listing, int $paidCents): void
    {
        $text = $listing->title."\n".$listing->body;
        $screening = [];

        if ($watch = WatchPhrases::matches($text)) {
            $screening['watch'] = $watch;
        }

        if (! $screening && OpenAiModeration::enabled()) {
            try {
                // The check reads a dispatch's text and photos; listings have text only.
                $result = OpenAiModeration::check((new Dispatch)->forceFill(['body' => $text]));
                if ($result['hold'] || $result['remove']) {
                    $screening['openai'] = $result['flagged'];
                }
            } catch (Throwable $e) {
                $screening['openai_failed'] = true;
            }
        }

        $listing->forceFill([
            'status' => $screening ? 'held' : 'active',
            'screening' => $screening ?: null,
            'expires_at' => now()->addDays(config('classifieds.days')),
            'paid_cents' => $listing->paid_cents + $paidCents,
        ])->save();
    }

    protected static function extend(Listing $listing, int $paidCents): void
    {
        $from = $listing->expires_at?->isFuture() ? $listing->expires_at : now();
        $listing->forceFill(['expires_at' => $from->copy()->addDays(config('classifieds.days')), 'paid_cents' => $listing->paid_cents + $paidCents])->save();
    }

    /**
     * Another 30 days. Free listings renew right away; paid ones return a Checkout link.
     */
    public static function renew(User $user, Listing $listing): ?string
    {
        abort_unless($listing->user_id === $user->id && $listing->status === 'active', 403);

        if (self::price($listing->category) === 0) {
            self::extend($listing, 0);

            return null;
        }

        return self::checkout($listing);
    }

    public static function markFilled(User $user, Listing $listing): void
    {
        abort_unless($listing->user_id === $user->id && $listing->status === 'active', 403);
        $listing->forceFill(['status' => 'filled'])->save();
    }

    /**
     * Message the poster about a listing. The first message names the listing; whether it goes
     * straight through or waits as a request follows the usual messaging rules.
     */
    public static function contact(User $from, Listing $listing, string $body): Conversation
    {
        abort_unless($listing->isLive(), 404);

        if (LostAndFound::hasContactInfo($body)) {
            throw ValidationException::withMessages(['body' => 'Keep it in Stoop messages for now: leave out phone numbers and emails.']);
        }

        $conversation = Messaging::start($from, [$listing->user]);
        Messaging::send($from, $conversation, 'About your listing “'.$listing->title.'” '.route('classifieds.show', $listing)."\n\n".trim($body));

        return $conversation;
    }

    /**
     * Enough reports from different people hide a listing until a moderator looks.
     */
    public static function report(User $user, Listing $listing, string $reason, ?string $note = null): void
    {
        Validator::make(['reason' => $reason, 'note' => $note], [
            'reason' => ['required', Rule::in([...array_keys(config('stoop.report_reasons')), 'scam', 'discrimination'])],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        abort_if($listing->user_id === $user->id, 422, 'You can’t report your own listing.');

        ListingReport::firstOrCreate(['listing_id' => $listing->id, 'user_id' => $user->id], ['reason' => $reason, 'note' => $note]);

        if ($listing->status === 'active' && $listing->reports()->whereNull('resolved_at')->count() >= config('stoop.report_threshold')) {
            $listing->forceFill(['status' => 'held', 'screening' => [...($listing->screening ?? []), 'reports' => true]])->save();
        }
    }

    /**
     * Live listings, newest first, narrowed by category, stop or line, and price (or pay).
     *
     * @return Builder<Listing>
     */
    public static function board(?string $category, ?string $stop, ?string $line, ?int $min, ?int $max, ?User $viewer): Builder
    {
        return Listing::live()->with('user')
            ->when($viewer, fn ($q) => $q->whereNotIn('user_id', $viewer->hiddenUserIds()))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($stop, fn ($q) => $q->where('stop', $stop))
            ->when($line && ! $stop, fn ($q) => $q->whereIn('stop', config("metro.lines.$line", [])))
            ->when($min !== null, fn ($q) => $q->where(fn ($q) => $q->where('price', '>=', $min)->orWhere('pay_max', '>=', $min)))
            ->when($max !== null, fn ($q) => $q->where(fn ($q) => $q->where('price', '<=', $max)->orWhere('pay_min', '<=', $max)))
            ->latest();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function stripe(string $call, mixed $arg): array
    {
        if (self::$stripe) {
            return (self::$stripe)($call, $arg);
        }

        $sessions = (new StripeClient((string) config('classifieds.stripe_secret')))->checkout->sessions;

        return ($call === 'create' ? $sessions->create($arg) : $sessions->retrieve($arg))->toArray();
    }
}
