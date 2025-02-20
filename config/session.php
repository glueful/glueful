<?php

/**
 * Session Configuration
 * 
 * Defines session handling and JWT token settings.
 * Controls token lifetimes and encryption parameters.
 */
return [
    // Token Lifetimes (in seconds)
    'access_token_lifetime' => env('ACCESS_TOKEN_LIFETIME', 900),      // 15 minutes
    'refresh_token_lifetime' => env('REFRESH_TOKEN_LIFETIME', 604800), // 7 days
    'remember_expiration' => 30 * 24 * 3600,                          // 30 days
    
    // Security Keys
    'token_salt' => env('TOKEN_SALT', 'jc=mwaO8{QdM8R]&RG4>`-6dG1zkmq2h1v7wHw*@U)VytS468{AHTHMA64l+c;1'),  // Salt for token generation
    'jwt_key' => env('JWT_KEY', 'YS%;)TUW81O5_cJ.Ky$)5f7M)hV8yISwE%w[)*xR9AW|++&q-)0O((]q~!>mBz<Y'),      // JWT signing key
    
    // JWT Settings
    'jwt_algorithm' => env('JWT_ALGORITHM', 'HS256'),  // JWT signing algorithm
];