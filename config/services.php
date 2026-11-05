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

    // Calls to other Dot platforms. Blank secret: nothing is sent and nothing breaks. Each secret must equal the
    // value set for the `sheet` platform on the other side, named beside it here.
    'notify' => [ // NOTIFY_INGEST_SECRET_SHEET on Dot.Notify
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => env('NOTIFY_PLATFORM_KEY', 'sheet'),
        'timeout' => (int) env('NOTIFY_TIMEOUT', 5),
    ],

    'analytics' => [ // ANALYTICS_INGEST_SECRET_SHEET on Dot.Analytics
        'url' => env('ANALYTICS_INGEST_URL', 'https://analytics.infodot.co.za/api/ingest/snapshots'),
        'secret' => env('ANALYTICS_INGEST_SECRET'),
        'timeout' => (int) env('ANALYTICS_TIMEOUT', 5),
    ],

    'files' => [ // DOT_SERVICE_SECRET_SHEET on Dot.Files
        'url' => env('FILES_API_URL', 'https://files.infodot.co.za'),
        'secret' => env('FILES_PLATFORM_SECRET'),
        'platform' => env('FILES_PLATFORM_KEY', 'sheet'),
        'folder' => env('FILES_FOLDER', 'Dot.Sheets'),
        'timeout' => (int) env('FILES_TIMEOUT', 20),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
