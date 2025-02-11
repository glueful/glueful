<?php

return [
    'smtp' => [
        'host' => env('SMTP_HOST', 'SMTP_HOST'),
        'username' => env('SMTP_USERNAME', 'SMTP_USERNAME'),
        'password' => env('SMTP_PASSWORD', 'SMTP_PASSWORD'),
        'secure' => env('SMTP_SECURE', 'tls'),
        'port' => env('SMTP_PORT', 587),
    ],
    'bcc' => env('BCC_EMAILS', ''),
    'sendgrid_key' => env('SENDGRID_API_KEY', ''),
    'brevo_key' => env('BREVO_API_KEY', ''),
    'force_advanced' => env('FORCE_ADVANCED_EMAIL', true),
];
