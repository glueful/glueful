<?php
return [
    'jwt' => [
        'secret' => env('JWT_SECRET', 'change-this-to-secure-secret'),
        'default_expiration' => 3600,
        'remember_expiration' => 30 * 24 * 3600,
    ],
];