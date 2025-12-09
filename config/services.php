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
        'token' => env('POSTMARK_TOKEN'),
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

    'modbus' => [
        'dwp' => [
            'port' => env('MODBUS_DWP_PORT', 503),
            'unit_id' => env('MODBUS_DWP_UNIT_ID', 1),
            'timeout' => env('MODBUS_DWP_TIMEOUT', 3),
            'value_divisor' => env('MODBUS_DWP_VALUE_DIVISOR', 10), // Divide raw values by this to get decimal
        ],
    ],

];
