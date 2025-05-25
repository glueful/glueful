<?php

/**
 * Session Configuration
 *
 * Defines session handling and JWT token settings.
 * SECURITY: All secrets moved to environment variables.
 */

return [
    // Token Lifetimes (in seconds)
    'access_token_lifetime' => env('ACCESS_TOKEN_LIFETIME', 900),      // 15 minutes
    'refresh_token_lifetime' => env('REFRESH_TOKEN_LIFETIME', 604800), // 7 days
    'remember_expiration' => 30 * 24 * 3600,                          // 30 days

    // Security Keys - MUST be set in environment variables
    'token_salt' => env('TOKEN_SALT'),  // REQUIRED: Strong random salt for token generation
    'jwt_key' => env('JWT_KEY'),        // REQUIRED: Strong random key for JWT signing

    // JWT Settings
    'jwt_algorithm' => env('JWT_ALGORITHM', 'HS256'),  // JWT signing algorithm
];
