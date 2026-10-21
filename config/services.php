<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'repo' => env('GITHUB_REPO', 'securaitllc/SecuraSNMP'),
    ],

    // OSINT enumeration sidecar (enum/ container): subfinder + dnsx brute-force.
    // URL defaults to the internal compose address so the app finds the sidecar with
    // no app-service env edit; the shared token comes from env OR, when unset, from the
    // 'osint_enum' provider key in Settings (so it can be set in the UI, not the YAML).
    'osint_enum' => [
        'url' => env('OSINT_ENUM_URL', 'http://enum:8099'),
        'token' => env('OSINT_ENUM_TOKEN'),
    ],

];
