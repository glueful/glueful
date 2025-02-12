<?php

return [
    'smtp' => [
        'host' => env('SMTP_HOST', 'SMTP_HOST'),
        'username' => env('SMTP_USERNAME', 'SMTP_USERNAME'),
        'password' => env('SMTP_PASSWORD', 'SMTP_PASSWORD'),
        'secure' => env('SMTP_SECURE', 'tls'),
        'port' => env('SMTP_PORT', 587),
        'useSmtp' => env('USE_SMTP', false),
        'auth' => env('SMTP_AUTH', true),
    ],
    'bcc' => env('BCC_EMAILS', ''),
    'force_advanced' => env('FORCE_ADVANCED_EMAIL', true),
];
