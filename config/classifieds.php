<?php

/*
 * Classifieds (app/Support/Classifieds.php). Prices are in cents per 30-day listing; 0 is free.
 * Paid categories need a Stripe TEST key (sk_test_… or rk_test_…) in STRIPE_SECRET; without
 * one they show "Coming soon" and only free categories can be posted.
 */
return [

    'categories' => [
        'jobs' => ['name' => 'Jobs', 'price' => 2500, 'housing' => false],
        'housing' => ['name' => 'Apartments & rooms', 'price' => 1500, 'housing' => true],
        'roommates' => ['name' => 'Roommates', 'price' => 1000, 'housing' => true],
        'forsale' => ['name' => 'For sale', 'price' => 0, 'housing' => false],
    ],

    'days' => 30,

    'stripe_secret' => env('STRIPE_SECRET'),

    'pay_periods' => ['hour' => 'an hour', 'year' => 'a year'],

    'fair_housing_url' => 'https://ohr.dc.gov/page/fairhousing',

    // Phrases that show a preference the DC Human Rights Act forbids in housing ads: source of
    // income (vouchers), familial status, race, national origin and religion. Roommate ads may
    // still say "female roommate" (the shared-housing exception), so gender isn't listed.
    'fair_housing_patterns' => [
        '/\bno\s+(section\s*8|vouchers?|hcvp?|housing\s+(choice\s+)?vouchers?|subsidi[sz]ed|government\s+assistance)\b/i',
        '/\b(section\s*8|vouchers?)\s+(not\s+(accepted|allowed|welcome)|need\s+not\s+apply)\b/i',
        '/\bno\s+(kids|children|families|babies|toddlers)\b/i',
        '/\b(adults?\s+only|child[\s-]?free\s+(building|home|household)|not\s+suitable\s+for\s+(kids|children))\b/i',
        '/\b(white|black|asian|hispanic|latino|latina|christian|muslim|jewish|catholic|hindu|caucasian|african[\s-]american)s?\s+(only|preferred|tenants?\s+only|applicants?\s+only)\b/i',
        '/\b(prefer|preferred|seeking|looking\s+for)\s+(a\s+)?(white|black|asian|hispanic|latino|latina|christian|muslim|jewish|catholic|hindu|caucasian)\b/i',
        '/\bno\s+(immigrants|foreigners|muslims|jews|christians|hispanics|blacks|asians)\b/i',
        '/\b(english[\s-]speakers?\s+only|must\s+be\s+(a\s+)?(citizen|christian|american[\s-]born))\b/i',
    ],

];
