<?php

return [

    // Stripe, TEST MODE ONLY: App\Support\Stripe refuses anything but sk_test_/rk_test_ keys.
    // Without a key, Upgrade and Promote are hidden and the admin says "Stripe not configured".
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Plans for place owners. A Business account covers every place its owner manages.
    'plans' => [
        'free' => ['name' => 'Free', 'price_cents' => 0, 'deals' => 1, 'locations' => 1, 'free_promotions' => 0],
        'business' => ['name' => 'Business', 'price_cents' => 4900, 'deals' => 3, 'locations' => 25, 'free_promotions' => 1,
            'stripe_price' => env('STRIPE_PRICE_BUSINESS')],
    ],

    // Stripe subscription statuses that count as Business. past_due keeps the perks while Stripe retries the card.
    'active_statuses' => ['active', 'trialing', 'past_due'],

    // Self-serve promotions: one payment per boost, delivered as a promoted ad up to the impressions.
    'promotion_tiers' => [
        'small' => ['cents' => 2500, 'impressions' => 2000],
        'medium' => ['cents' => 5000, 'impressions' => 5000],
        'large' => ['cents' => 10000, 'impressions' => 12000],
    ],

    // The tier a Business account's free monthly promoted happening gets.
    'free_promotion_tier' => 'small',

    'promotion_max_days' => 30,

];
