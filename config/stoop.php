<?php

return [

    // Metro lines double as topic channels.
    'lines' => [
        'red' => ['name' => 'Food', 'color' => '#bf0d3e'],
        'orange' => ['name' => 'Going Out', 'color' => '#ed8b00'],
        'blue' => ['name' => 'Culture', 'color' => '#009cde'],
        'green' => ['name' => 'Real Estate', 'color' => '#00b140'],
        'yellow' => ['name' => 'Ask DC', 'color' => '#e0b400'],
        'silver' => ['name' => 'MD/VA', 'color' => '#919d9d'],
    ],

    // Every Metrorail station, by Stoop's station code (see config/metro.php).
    'stations' => array_map(fn ($station) => $station[0], (require __DIR__.'/metro.php')['stations']),

    'types' => [
        'dispatch' => 'Dispatch',
        'ask' => 'Ask DC',
        'poll' => 'Poll',
        'happening' => 'Happening',
        'been' => 'Been here',
        'safety' => 'Crime & safety',
        'snow' => 'Snow report',
        'callout' => 'Callout',
    ],

    'report_reasons' => [
        'hate' => 'Hate or harassment',
        'profiling' => 'Profiling someone by race or identity',
        'spam' => 'Spam or self-promotion',
        'campaign' => 'Campaign or candidate post',
        'other' => 'Something else',
    ],

    // Distinct reports that hide a dispatch for everyone until a moderator reviews it.
    'report_threshold' => 3,

    // Reasons moderators pick from when removing a post or issuing a penalty.
    'rule_reasons' => [
        'Attacking people for who they are',
        'Profiling someone by race or identity',
        'Threats or harassment',
        'Campaign or candidate promotion',
        'Spam or networking pitches',
        'Sharing someone’s private information',
    ],

    // Replies in 10 minutes from people who don't follow the author that pause replies
    // on a thread for an hour and flag it for moderators.
    'pile_on_threshold' => 15,

    // The magazine's RSS or Atom feed. New articles post to Stoop from the account below.
    'magazine_feed' => env('STOOP_MAGAZINE_FEED'),
    'magazine_account' => env('STOOP_MAGAZINE_ACCOUNT', 'magazine'),
    'magazine_name' => env('STOOP_MAGAZINE_NAME', 'From the Magazine'),

    // Mark new accounts as verified on sign-up, skipping the email link.
    'auto_verify' => env('STOOP_AUTO_VERIFY', true),

    // Turn on once posting is tied to magazine subscriptions.
    'require_subscription' => env('STOOP_REQUIRE_SUBSCRIPTION', false),

    // Local words that get a hover definition in posts.
    'glossary' => [
        'the District' => 'Washington, DC.',
        'DMV' => 'DC, Maryland and Virginia. Not the motor vehicle office.',
        'the Beltway' => 'I-495, the highway ringing the city; also shorthand for the federal political world.',
        'go-go' => 'DC’s own funk-driven music, born here in the 1970s. Chuck Brown is the Godfather of Go-Go.',
        'mumbo sauce' => 'The sweet, tangy red sauce from DC carryouts, on wings and fries.',
        'half-smoke' => 'DC’s signature sausage: coarse, spicy, half pork and half beef, usually grilled and split.',
        'ANC' => 'Advisory Neighborhood Commission: elected neighbors who weigh in on local issues like liquor licenses and zoning.',
        'NoVa' => 'Northern Virginia.',
        'MoCo' => 'Montgomery County, Maryland.',
        'single-tracking' => 'Trains sharing one track to get around work. Expect delays.',
        'SmarTrip' => 'Metro’s fare card.',
        'Chocolate City' => 'A nickname for DC from its history as a majority-Black city, and the title of a 1975 Parliament album.',
    ],

    // Days, streaks and half-smoke allowances follow DC time.
    'timezone' => 'America/New_York',

    'half_smokes_per_day' => 10,

    // Hosts you can link when you go live from somewhere.
    'live_hosts' => ['youtube.com', 'www.youtube.com', 'youtu.be', 'twitch.tv', 'www.twitch.tv', 'instagram.com', 'www.instagram.com'],

    'max_length' => 500,

    'posts_per_minute' => 10,

    // Posts matching these are held for review instead of published. Add slur and
    // threat patterns here; the nudge list below only asks the author to reconsider.
    // ponytail: regex lists miss a lot, swap in a moderation model when traffic is real
    'held_patterns' => [],

    // OpenAI moderation scores (0 to 1) at or above which a post is held for moderators.
    // Lower holds more. Hate and threats are strict; self-harm goes to a person who can respond with care.
    'openai_thresholds' => [
        'hate' => 0.4,
        'hate/threatening' => 0.2,
        'harassment' => 0.6,
        'harassment/threatening' => 0.3,
        'violence' => 0.7,
        'violence/graphic' => 0.6,
        'sexual' => 0.7,
        'sexual/minors' => 0.05,
        'self-harm' => 0.5,
        'self-harm/intent' => 0.3,
        'self-harm/instructions' => 0.3,
        'illicit/violent' => 0.5,
    ],

    // Scores at or above which the automatic check removes a post outright and gives the
    // author a strike (see app/Support/Strikes.php). Everything between the hold and
    // remove thresholds waits for a person.
    'openai_auto_remove' => [
        'hate' => 0.85,
        'hate/threatening' => 0.6,
        'harassment/threatening' => 0.7,
        'sexual/minors' => 0.3,
        'illicit/violent' => 0.85,
    ],

    // Plain-language reasons for automatic removals.
    'openai_reasons' => [
        'hate' => 'Attacking people for who they are',
        'hate/threatening' => 'Threatening people for who they are',
        'harassment/threatening' => 'Threats or harassment',
        'sexual/minors' => 'Sexual content involving minors',
        'illicit/violent' => 'Promoting violence',
    ],

    // Screen every new dispatch with Claude before it goes public (needs ANTHROPIC_API_KEY).
    'model_moderation' => env('STOOP_MODEL_MODERATION', true),

    // New accounts: how long before they can send message requests, and a daily post cap for their first week.
    'new_account_dm_hours' => 72,
    'new_account_daily_posts' => 20,

    'nudge_pattern' => '/\b(idiots?|stupid|morons?|trash people|go back to|shut up|losers?|scum|hate (you|them|these))\b/i',

];
