# Social Login Extension for Glueful

## Overview

The Social Login extension adds OAuth authentication capabilities to your Glueful application, allowing users to sign in using popular social platforms:

- Google
- Facebook
- GitHub
- Apple

## Features

- Multiple provider support
- Automatic user registration
- Profile synchronization
- Account linking
- Token generation and session management
- Configuration through admin interface

## Installation

1. Clone this repository into your `extensions` directory.
2. Add the extension to your enabled extensions list in the config file:

```php
// In config/extensions.php
return [
    'enabled' => [
        // other extensions...
        'SocialLogin',
    ],
];
```

3. Run the migrations to create the necessary database tables:

```bash
php glueful db:migrate
```

4. Generate API documentation:

```bash
php glueful generate:json doc
```

This will automatically:
- Create the database table schema for social_accounts
- Scan and parse your extension's route files
- Generate OpenAPI documentation for all your social login endpoints
- Add them to the main API documentation

5. Restart your web server to apply the changes.

## Configuration

### API Credentials

You'll need to obtain OAuth credentials from each provider you want to support:

#### Google

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to "APIs & Services" > "Credentials"
4. Create an OAuth 2.0 Client ID
5. Set the authorized redirect URI to: `https://yourdomain.com/auth/social/google/callback`

#### Facebook

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app
3. Add the Facebook Login product
4. Configure the Valid OAuth Redirect URI as: `https://yourdomain.com/auth/social/facebook/callback`

#### GitHub

1. Go to [GitHub Developer Settings](https://github.com/settings/developers)
2. Create a new OAuth App
3. Set the authorization callback URL to: `https://yourdomain.com/auth/social/github/callback`

#### Apple

1. Go to your [Apple Developer Account](https://developer.apple.com/)
2. Navigate to "Certificates, Identifiers & Profiles"
3. Create a Services ID under Identifiers
4. Enable "Sign in with Apple" and configure your domain and return URLs
5. Create or use an existing private key for client secret generation
6. Set the authorized redirect URI to: `https://yourdomain.com/auth/social/apple/callback`

### Environment Variables

Add your credentials to your `.env` file:

```
# Google
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/social/google/callback

# Facebook
FACEBOOK_APP_ID=your-app-id
FACEBOOK_APP_SECRET=your-app-secret
FACEBOOK_REDIRECT_URI=https://yourdomain.com/auth/social/facebook/callback

# GitHub
GITHUB_CLIENT_ID=your-client-id
GITHUB_CLIENT_SECRET=your-client-secret
GITHUB_REDIRECT_URI=https://yourdomain.com/auth/social/github/callback

# Apple
APPLE_CLIENT_ID=your-services-id
APPLE_CLIENT_SECRET=path-to-your-private-key.p8
APPLE_TEAM_ID=your-team-id
APPLE_KEY_ID=your-key-id
APPLE_REDIRECT_URI=https://yourdomain.com/auth/social/apple/callback
```

#### Description of Required Environment Variables

**Google Authentication:**
- `GOOGLE_CLIENT_ID`: The client ID obtained from Google Cloud Console OAuth credentials
- `GOOGLE_CLIENT_SECRET`: The client secret obtained from Google Cloud Console OAuth credentials
- `GOOGLE_REDIRECT_URI`: The callback URL that Google will redirect to after authentication (must match the authorized redirect URI in Google Cloud Console)

**Facebook Authentication:**
- `FACEBOOK_APP_ID`: The application ID obtained from Facebook Developers portal
- `FACEBOOK_APP_SECRET`: The application secret obtained from Facebook Developers portal
- `FACEBOOK_REDIRECT_URI`: The callback URL that Facebook will redirect to after authentication (must match the Valid OAuth Redirect URI in Facebook App settings)

**GitHub Authentication:**
- `GITHUB_CLIENT_ID`: The client ID obtained from GitHub Developer settings
- `GITHUB_CLIENT_SECRET`: The client secret obtained from GitHub Developer settings
- `GITHUB_REDIRECT_URI`: The callback URL that GitHub will redirect to after authentication (must match the authorization callback URL in GitHub OAuth App settings)

**Apple Authentication:**
- `APPLE_CLIENT_ID`: Your Services ID from Apple Developer account (e.g., com.yourdomain.client)
- `APPLE_CLIENT_SECRET`: Path to your private key file (.p8) or the key content directly (used to generate JWT tokens for Apple authentication)
- `APPLE_TEAM_ID`: Your Team ID from Apple Developer account membership information
- `APPLE_KEY_ID`: The Key ID from the private key created in your Apple Developer account
- `APPLE_REDIRECT_URI`: The callback URL that Apple will redirect to after authentication (must be registered in your Apple Developer account)

## Usage

### Login Buttons

Add social login buttons to your login page:

```html
<a href="/auth/social/google" class="btn-google">Sign in with Google</a>
<a href="/auth/social/facebook" class="btn-facebook">Sign in with Facebook</a>
<a href="/auth/social/github" class="btn-github">Sign in with GitHub</a>
<a href="/auth/social/apple" class="btn-apple">Sign in with Apple</a>
```

### Account Management

Users can manage their connected social accounts at:

```
GET /user/social-accounts
```

To unlink a social account:

```
DELETE /user/social-accounts/{uuid}
```

## Customization

You can customize the extension behavior through the `config.php` file:

```php
return [
    'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
    'auto_register' => true,  // Automatically create user accounts
    'link_accounts' => true,  // Allow linking social accounts to existing users
    'sync_profile' => true,   // Sync profile data from social providers
    
    // Provider specific settings...
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'team_id' => env('APPLE_TEAM_ID', ''),
        'key_id' => env('APPLE_KEY_ID', ''),
        'redirect_uri' => env('APPLE_REDIRECT_URI', ''),
    ],
];
```

## Apple Sign In Notes

- Apple Sign In requires a secure HTTPS domain
- Apple only provides name information during the first authentication
- For production use, your domain must be registered with Apple
- The client secret is a JWT generated using your private key

## Security Considerations

- Always use HTTPS for social login endpoints
- Validate state parameters to prevent CSRF attacks
- Use environment variables for client secrets
- Keep OAuth credentials confidential
- Review and customize the user creation logic as needed

## License

This extension is licensed under the same license as the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.