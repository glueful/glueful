<?php
return [
    'access_token_lifetime' => env('ACCESS_TOKEN_LIFETIME', 900),
    'refresh_token_lifetime' => env('REFRESH_TOKEN_LIFETIME', 604800),
    'remember_expiration' => 30 * 24 * 3600,
    'token_salt' => env('TOKEN_SALT', 'jc=mwaO8{QdM8R]&RG4>`-6dG1zkmq2h1v7wHw*@U)VytS468{AHTHMA64l+c;1'),
    'jwt_key' => env('JWT_KEY', 'YS%;)TUW81O5_cJ.Ky$)5f7M)hV8yISwE%w[)*xR9AW|++&q-)0O((]q~!>mBz<Y'),
    'jwt_algorithm' => env('JWT_ALGORITHM', 'HS256'),
];