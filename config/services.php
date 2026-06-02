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

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'secure' => env('CLOUDINARY_SECURE', true),
        'folder' => env('CLOUDINARY_FOLDER', 'red-aviation'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        'bank_name' => env('WIRE_BANK_NAME', 'Banco por configurar'),
        'bank_beneficiary' => env('WIRE_BANK_BENEFICIARY', 'Red Aviation'),
        'bank_account' => env('WIRE_BANK_ACCOUNT', 'Por configurar'),
        'bank_clabe' => env('WIRE_BANK_CLABE', 'Por configurar'),
        'bank_swift' => env('WIRE_BANK_SWIFT', ''),
    ],

    'docusign' => [
        'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
        'user_id' => env('DOCUSIGN_USER_ID'),
        'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
        'base_path' => env('DOCUSIGN_BASE_PATH', 'https://demo.docusign.net/restapi'),
        'oauth_base_path' => env('DOCUSIGN_OAUTH_BASE_PATH', 'account-d.docusign.com'),
        'connect_timeout' => (int) env('DOCUSIGN_CONNECT_TIMEOUT', 10),
        'request_timeout' => (int) env('DOCUSIGN_REQUEST_TIMEOUT', 30),
        'private_key' => env('DOCUSIGN_PRIVATE_KEY'),
        'private_key_path' => env('DOCUSIGN_PRIVATE_KEY_PATH', 'storage/app/docusign/private.key'),
        'frontend_url' => env('APP_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:5173')),
        'backend_url' => env('APP_BACKEND_URL', env('APP_URL', 'http://localhost:8000')),
        'return_path' => env('DOCUSIGN_RETURN_PATH', '/cliente/historial/'),
        'webhook_secret' => env('DOCUSIGN_WEBHOOK_SECRET'),
    ],

];
