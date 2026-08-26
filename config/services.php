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

    /*
     * OpenAI. Strictly optional: the engine computes every figure, and the
     * model is only ever handed a finished answer and asked to phrase it.
     * Leave the key blank and the product behaves identically, in the engine's
     * own words.
     *
     * The key lives in .env only. `enabled` is a separate switch, so the app
     * can be forced onto the deterministic engine even with a key present —
     * which is also what happens automatically if the key is absent.
     */
    'openai' => [
        'key'      => env('OPENAI_API_KEY'),
        'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'enabled'  => env('OPENAI_ENABLED', false),
        // Blank means OpenAI direct. Set only to route through a gateway.
        'endpoint' => env('OPENAI_ENDPOINT'),
    ],

];
