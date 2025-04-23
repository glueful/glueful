# Social Login Extension for Glueful

## Overview

The Social Login extension adds OAuth authentication capabilities to your Glueful application, allowing users to sign in using popular social platforms:

- Google
- Facebook
- GitHub

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
php glueful migrate
```

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
```

## Usage

### Login Buttons

Add social login buttons to your login page:

```html
<a href="/auth/social/google" class="btn-google">Sign in with Google</a>
<a href="/auth/social/facebook" class="btn-facebook">Sign in with Facebook</a>
<a href="/auth/social/github" class="btn-github">Sign in with GitHub</a>
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
    'enabled_providers' => ['google', 'facebook', 'github'],
    'auto_register' => true,  // Automatically create user accounts
    'link_accounts' => true,  // Allow linking social accounts to existing users
    'sync_profile' => true,   // Sync profile data from social providers
    
    // Provider specific settings...
];
```

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