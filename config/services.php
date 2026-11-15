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

    /*
     * The Kadi site's signed back-office API (kadi docs/backoffice-api.md). The
     * secret is one of the site's BACKOFFICE_GMS_SECRETS; never log it.
     */
    'kadi_site' => [
        'url' => env('KADI_SITE_BACKOFFICE_URL'),
        'secret' => env('KADI_SITE_BACKOFFICE_SECRET'),
        'timeout' => (int) env('KADI_SITE_BACKOFFICE_TIMEOUT', 20),
        'connect_timeout' => (int) env('KADI_SITE_BACKOFFICE_CONNECT_TIMEOUT', 5),
    ],

    'game_api' => [
        'url' => env('GAME_API_URL'),
        'key' => env('GAME_API_KEY'),
        'openssl_key' => env('GAME_API_OPENSSL_KEY'),
        'openssl_method' => env('GAME_API_OPENSSL_METHOD', 'AES-128-CBC'),
        'image_url' => env('KADI_IMAGE_URL', 'https://gameapi.kadi.online/kadi/images'),
        // The player app's referral link; {code} is replaced with the upper-case code.
        'referral_link' => env('KADI_REFERRAL_LINK', 'https://kadi.online/register?ref={code}'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL'),
        'sender_name' => env('BREVO_SENDER_NAME'),
        'endpoint' => env('BREVO_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
    ],

];
