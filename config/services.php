<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Push notifications. Generate keys with: php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Cloudflare Turnstile on sign-up. Leave empty to skip the check (local development).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
    ],

    // Shared secret the magazine's subscription system signs webhooks with.
    'subscriptions' => [
        'secret' => env('STOOP_SUBSCRIPTION_SECRET'),
    ],

    // Optional. Shows live Metro delays in the sidebar. Free key at developer.wmata.com.
    'wmata' => [
        'key' => env('WMATA_API_KEY'),
    ],

    // OpenAI's free moderation endpoint, the first check on every new post.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    // Optional. Without a key, @stoop answers from the knowledge base by keyword.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('STOOP_BOT_MODEL', 'claude-opus-5-5'),
        'moderation_model' => env('STOOP_MODERATION_MODEL', 'claude-opus-5-5'),
    ],

];
