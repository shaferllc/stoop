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

    // WMATA station codes people can tag a dispatch with.
    'stations' => [
        'A01' => 'Metro Center',
        'A02' => 'Farragut North',
        'A03' => 'Dupont Circle',
        'A04' => 'Woodley Park',
        'A05' => 'Cleveland Park',
        'A07' => 'Tenleytown',
        'A09' => 'Bethesda',
        'B01' => 'Gallery Place',
        'B02' => 'Judiciary Sq',
        'B03' => 'Union Station',
        'B35' => 'NoMa',
        'B05' => 'Brookland',
        'B07' => 'Takoma',
        'B08' => 'Silver Spring',
        'C02' => 'McPherson Sq',
        'C04' => 'Foggy Bottom',
        'C05' => 'Rosslyn',
        'C08' => 'Pentagon City',
        'C12' => 'Braddock Rd',
        'C13' => 'King St',
        'D05' => 'Capitol South',
        'D06' => 'Eastern Market',
        'D07' => 'Potomac Ave',
        'E02' => 'Shaw',
        'E03' => 'U Street',
        'E04' => 'Columbia Hts',
        'E05' => 'Petworth',
        'F04' => 'Waterfront',
        'F05' => 'Navy Yard',
        'F06' => 'Anacostia',
        'K01' => 'Court House',
        'K02' => 'Clarendon',
        'K04' => 'Ballston',
    ],

    'types' => [
        'dispatch' => 'Dispatch',
        'ask' => 'Ask DC',
        'poll' => 'Poll',
        'happening' => 'Happening',
        'been' => 'Been here',
        'safety' => 'Crime & safety',
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

    // Mark new accounts as verified on sign-up, skipping the email link.
    'auto_verify' => env('STOOP_AUTO_VERIFY', true),

    // Turn on once posting is tied to magazine subscriptions.
    'require_subscription' => env('STOOP_REQUIRE_SUBSCRIPTION', false),

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

    'nudge_pattern' => '/\b(idiots?|stupid|morons?|trash people|go back to|shut up|losers?|scum|hate (you|them|these))\b/i',

];
