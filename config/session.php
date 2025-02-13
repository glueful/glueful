<?php
return [
    'access_token_lifetime' => env('ACCESS_TOKEN_LIFETIME', 900),
    'refresh_token_lifetime' => env('REFRESH_TOKEN_LIFETIME', 604800),
    'remember_expiration' => 30 * 24 * 3600,
    'secret' => env('JWT_SECRET', 'change-this-to-secure-secret'),
    'token_salt' => env('TOKEN_SALT', 'change-this-to-secure-salt'),
];