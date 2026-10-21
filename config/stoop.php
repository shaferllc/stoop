<?php

return [

    // Paid AI is for moderation only. Everything else uses plain rules and free public data.
    // Add "content" (STOOP_AI_FEATURES=moderation,content) to let bots, translation, Catch me up,
    // composer suggestions, story ideas and summaries use it too.
    'ai_features' => array_filter(explode(',', env('STOOP_AI_FEATURES', 'moderation'))),

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
        'lost' => 'Lost & found',
        'snow' => 'Snow report',
        'openhouse' => 'Open house',
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

    // Sites allowed to call the embed APIs from the browser (neighborhood, place, quote, poll, byline boxes).
    'magazine_origins' => array_filter(explode(',', (string) env('STOOP_MAGAZINE_ORIGINS', 'https://www.washingtonian.com,https://washingtonian.com'))),

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
    // Stoop is strict: anything that even leans toward hate or harassment waits for a person.
    'openai_thresholds' => [
        'hate' => 0.15,
        'hate/threatening' => 0.1,
        'harassment' => 0.35,
        'harassment/threatening' => 0.15,
        'violence' => 0.5,
        'violence/graphic' => 0.5,
        'sexual' => 0.5,
        'sexual/minors' => 0.05,
        'self-harm' => 0.5,
        'self-harm/intent' => 0.3,
        'self-harm/instructions' => 0.3,
        'illicit/violent' => 0.4,
    ],

    // Scores at or above which the automatic check removes a post outright and gives the
    // author a strike (see app/Support/Strikes.php). Hate speech is removed at a much lower
    // score than anything else, and its strikes are harsher.
    'openai_auto_remove' => [
        'hate' => 0.45,
        'hate/threatening' => 0.25,
        'harassment' => 0.8,
        'harassment/threatening' => 0.5,
        'sexual/minors' => 0.1,
        'illicit/violent' => 0.7,
    ],

    // Categories that count as hate speech: their strikes use the severe ladder.
    'hate_categories' => ['hate', 'hate/threatening'],

    // Plain-language reasons for automatic removals.
    'openai_reasons' => [
        'hate' => 'Attacking people for who they are',
        'hate/threatening' => 'Threatening people for who they are',
        'harassment' => 'Harassment',
        'harassment/threatening' => 'Threats or harassment',
        'sexual/minors' => 'Sexual content involving minors',
        'illicit/violent' => 'Promoting violence',
    ],

    // Screen every new dispatch with Claude before it goes public (needs ANTHROPIC_API_KEY).
    'model_moderation' => env('STOOP_MODEL_MODERATION', true),

    // New accounts: how long before they can send message requests, and a daily post cap for their first week.
    'new_account_dm_hours' => 72,
    'new_account_daily_posts' => 20,

    // Sign-up risk (app/Support/SignupRisk.php): throwaway email domains, and the score
    // (0 to 100) at which a new account's first posts wait for a moderator.
    'disposable_email_domains' => ['mailinator.com', 'guerrillamail.com', 'sharklasers.com', '10minutemail.com', 'temp-mail.org', 'tempmail.com', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mohmal.com', 'emailondeck.com'],
    'signup_risk_hold' => 50,

    'nudge_pattern' => '/\b(idiots?|stupid|morons?|trash people|go back to|shut up|losers?|scum|hate (you|them|these)|f+u+c+k+ (you|u|off|them|this guy)|fck (you|u|off)|screw (you|u)|go to hell|kill yourself|kys)\b/i',

];
