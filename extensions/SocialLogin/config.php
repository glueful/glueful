<?php

/**
 * Social Login Extension Configuration
 *
 * Configuration settings for social authentication providers.
 * You can customize these settings based on your application needs.
 */

return [
    // General settings
    'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
    'auto_register' => true,  // Automatically create user accounts for new social logins
    'link_accounts' => true,  // Allow linking social accounts to existing users
    'sync_profile' => true,   // Sync profile data from social providers

    // Google OAuth settings
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
    ],

    // Facebook OAuth settings
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
        'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI', ''),
    ],

    // GitHub OAuth settings
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID', ''),
        'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        'redirect_uri' => env('GITHUB_REDIRECT_URI', ''),
    ],

    // Apple OAuth settings
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'team_id' => env('APPLE_TEAM_ID', ''),
        'key_id' => env('APPLE_KEY_ID', ''),
        'redirect_uri' => env('APPLE_REDIRECT_URI', ''),
    ],
];
