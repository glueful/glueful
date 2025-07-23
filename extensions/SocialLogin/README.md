# Social Login Extension for Glueful

## Overview

The SocialLogin extension provides enterprise-grade OAuth authentication for the Glueful Framework, enabling seamless integration with major social platforms. It supports both web-based OAuth flows and native mobile app authentication, with automatic user registration, account linking, and comprehensive security features.

## Features

- ✅ **Multi-Platform Support** - Google, Facebook, GitHub, and Apple Sign In
- ✅ **Dual Authentication Flows** - Web OAuth redirects and native mobile token verification
- ✅ **Enterprise Security** - CSRF protection, JWT validation, and secure token management
- ✅ **Automatic User Management** - Registration, account linking, and profile synchronization
- ✅ **Advanced Apple Integration** - Custom ASN.1 JWT parser and Sign In with Apple support
- ✅ **Database Integration** - Social account associations with foreign key relationships
- ✅ **Comprehensive API** - RESTful endpoints with OpenAPI documentation
- ✅ **Health Monitoring** - Built-in diagnostics and configuration validation
- ✅ **Flexible Configuration** - Environment variables and runtime configuration

## Requirements

- PHP 8.2 or higher
- Glueful Framework 0.27.0 or higher
- cURL PHP extension
- OpenSSL PHP extension (for Apple Sign In)

## Installation

### Automatic Installation (Recommended)

**Option 1: Using the CLI**

```bash
# Enable the extension if it's already present
php glueful extensions enable SocialLogin

# Or install from external source
php glueful extensions install <source-url-or-file> SocialLogin
```

**Option 2: Manual Installation**

1. Copy the SocialLogin extension to your `extensions/` directory
2. Add to `extensions/extensions.json`:
```json
{
  "extensions": {
    "SocialLogin": {
      "version": "0.18.0",
      "enabled": true,
      "installPath": "extensions/SocialLogin",
      "type": "optional",
      "description": "Social authentication with OAuth providers",
      "author": "glueful-team"
    }
  }
}
```

3. Run migrations to create the database table:
```bash
php glueful migrate run
```

4. Enable the extension:
```bash
php glueful extensions enable SocialLogin
```

### Verify Installation

Check that the extension is properly enabled:

```bash
php glueful extensions list
php glueful extensions info SocialLogin

# Generate API documentation
php glueful generate:json doc
```

## Configuration

### Provider Credentials Setup

Obtain OAuth credentials from each provider you want to support:

#### Google OAuth Setup

1. Visit [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Navigate to "APIs & Services" > "Credentials"
4. Create OAuth 2.0 Client ID
5. Add authorized redirect URI: `https://yourdomain.com/auth/social/google/callback`
6. Enable Google+ API and Google People API

#### Facebook OAuth Setup

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app or select existing
3. Add Facebook Login product
4. Configure Valid OAuth Redirect URIs: `https://yourdomain.com/auth/social/facebook/callback`
5. Set required permissions: `email`, `public_profile`

#### GitHub OAuth Setup

1. Go to [GitHub Developer Settings](https://github.com/settings/developers)
2. Create new OAuth App
3. Set Authorization callback URL: `https://yourdomain.com/auth/social/github/callback`
4. Configure required scopes: `user:email`, `read:user`

#### Apple Sign In Setup

1. Visit [Apple Developer Account](https://developer.apple.com/)
2. Navigate to "Certificates, Identifiers & Profiles"
3. Create Services ID under Identifiers
4. Enable "Sign in with Apple" capability
5. Configure domain and return URLs
6. Create or reuse a private key (`.p8` file)
7. Set redirect URI: `https://yourdomain.com/auth/social/apple/callback`

### Environment Variables

Configure OAuth credentials in your `.env` file:

```env
# Google OAuth Configuration
GOOGLE_CLIENT_ID=your-google-client-id.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/social/google/callback

# Facebook OAuth Configuration
FACEBOOK_APP_ID=your-facebook-app-id
FACEBOOK_APP_SECRET=your-facebook-app-secret
FACEBOOK_REDIRECT_URI=https://yourdomain.com/auth/social/facebook/callback

# GitHub OAuth Configuration
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret
GITHUB_REDIRECT_URI=https://yourdomain.com/auth/social/github/callback

# Apple Sign In Configuration
APPLE_CLIENT_ID=com.yourdomain.services.id
APPLE_CLIENT_SECRET=/path/to/AuthKey_XXXXXXXXXX.p8
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_REDIRECT_URI=https://yourdomain.com/auth/social/apple/callback

# Extension Configuration
SOCIAL_LOGIN_AUTO_REGISTER=true
SOCIAL_LOGIN_LINK_ACCOUNTS=true
SOCIAL_LOGIN_SYNC_PROFILE=true
```

### Extension Configuration

Customize behavior in the extension's `config.php`:

```php
return [
    'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
    'auto_register' => env('SOCIAL_LOGIN_AUTO_REGISTER', true),
    'link_accounts' => env('SOCIAL_LOGIN_LINK_ACCOUNTS', true),
    'sync_profile' => env('SOCIAL_LOGIN_SYNC_PROFILE', true),
    
    // Provider configurations
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => ['openid', 'profile', 'email'],
    ],
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'scopes' => ['email', 'public_profile'],
    ],
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI'),
        'scopes' => ['user:email', 'read:user'],
    ],
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'redirect_uri' => env('APPLE_REDIRECT_URI'),
    ],
];
```

## Usage

### Web-Based OAuth Flow

For traditional web applications, use redirect-based OAuth:

```html
<!-- Social login buttons -->
<div class="social-login-buttons">
    <a href="/auth/social/google" class="btn btn-google">
        <i class="fab fa-google"></i> Sign in with Google
    </a>
    <a href="/auth/social/facebook" class="btn btn-facebook">
        <i class="fab fa-facebook-f"></i> Sign in with Facebook
    </a>
    <a href="/auth/social/github" class="btn btn-github">
        <i class="fab fa-github"></i> Sign in with GitHub
    </a>
    <a href="/auth/social/apple" class="btn btn-apple">
        <i class="fab fa-apple"></i> Sign in with Apple
    </a>
</div>
```

### Native Mobile App Flow

For mobile applications, use direct token verification:

```javascript
// Example: React Native with Google Sign In
import { GoogleSignin } from '@react-native-google-signin/google-signin';

// Configure Google Sign In
GoogleSignin.configure({
  webClientId: 'your-google-client-id.googleusercontent.com',
});

// Sign in and get tokens
const signIn = async () => {
  try {
    await GoogleSignin.hasPlayServices();
    const { idToken, accessToken } = await GoogleSignin.signIn();
    
    // Send tokens to your Glueful backend
    const response = await fetch('https://yourapi.com/auth/social/google', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        id_token: idToken,
        access_token: accessToken,
      }),
    });
    
    const result = await response.json();
    // Handle authentication result
  } catch (error) {
    console.error('Sign in failed:', error);
  }
};
```

### Backend Token Verification

```php
// For native mobile apps, POST tokens directly
POST /auth/social/google
Content-Type: application/json

{
    "id_token": "google-id-token-here",
    "access_token": "google-access-token-here"
}

// Response
{
    "success": true,
    "message": "Authentication successful",
    "data": {
        "access_token": "your-app-jwt-token",
        "refresh_token": "your-app-refresh-token",
        "user": {
            "uuid": "user-uuid",
            "email": "user@example.com",
            "name": "User Name"
        },
        "social_account": {
            "uuid": "social-account-uuid",
            "provider": "google",
            "social_id": "google-user-id"
        }
    }
}
```

## API Endpoints

The extension provides comprehensive REST API endpoints:

### Authentication Endpoints

```php
// Web OAuth Flow
GET  /auth/social/{provider}              // Initiate OAuth flow
GET  /auth/social/{provider}/callback     // OAuth callback handler

// Native App Flow
POST /auth/social/{provider}              // Direct token verification

// Apple-specific (supports both GET and POST)
GET  /auth/social/apple                   // Apple OAuth initiation
POST /auth/social/apple/callback          // Apple callback (POST only)
```

### Account Management Endpoints

```php
// Social account management
GET    /user/social-accounts              // List connected social accounts
DELETE /user/social-accounts/{uuid}       // Unlink social account

// User profile operations
GET    /user/profile                      // Get user profile
PUT    /user/profile                      // Update user profile
```

### Example API Usage

```bash
# List connected social accounts
curl -H "Authorization: Bearer your-jwt-token" \
     https://yourapi.com/user/social-accounts

# Unlink a social account
curl -X DELETE \
     -H "Authorization: Bearer your-jwt-token" \
     https://yourapi.com/user/social-accounts/social-account-uuid
```

## Advanced Features

### Automatic User Registration

When `auto_register` is enabled, the extension automatically creates user accounts:

```php
// User creation process
1. Verify social provider token/ID token
2. Extract user profile information
3. Check if user exists by email
4. Create new user if not exists
5. Create social account association
6. Generate application JWT tokens
7. Return authentication response
```

### Account Linking

Link social accounts to existing users:

```php
// Account linking process
1. User authenticates with social provider
2. System finds existing user by email
3. Links social account to existing user
4. Updates profile if sync_profile is enabled
5. Returns authentication tokens
```

### Profile Synchronization

Automatically sync user profiles from social providers:

```php
// Synced profile fields
- Name (first_name, last_name, display_name)
- Email address
- Profile photo URL
- Social provider ID
- Additional provider-specific data
```

### Apple Sign In Specifics

The extension includes advanced Apple Sign In support:

#### Custom ASN.1 Parser

Validates Apple's JWT signatures using a custom ASN.1 parser:

```php
use Glueful\Extensions\SocialLogin\Providers\ASN1Parser;

// Automatic JWT validation with Apple's public keys
$parser = new ASN1Parser();
$isValid = $parser->validateAppleIdToken($idToken);
```

#### Apple-Specific Considerations

- **First-Time Data**: Apple only provides name and email on first authentication
- **Privacy**: Users can choose to hide their email (Apple provides a proxy email)
- **JWT Validation**: Requires complex signature verification with Apple's rotating keys

## Database Schema

The extension creates a `social_accounts` table:

```sql
CREATE TABLE social_accounts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL UNIQUE,
    user_uuid CHAR(12) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    social_id VARCHAR(255) NOT NULL,
    profile_data TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_uuid) REFERENCES users(uuid) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_social (provider, social_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_provider (provider)
);
```

## Security Features

### CSRF Protection

State parameter validation prevents CSRF attacks:

```php
// Automatic state generation and validation
$state = bin2hex(random_bytes(16));
// State is verified on OAuth callback
```

### JWT Token Management

Secure token generation and validation:

```php
// Uses Glueful's TokenManager for secure JWT tokens
$tokens = $this->tokenManager->generateTokenPair($userUuid, [
    'social_provider' => $provider,
    'social_id' => $socialId
]);
```

### Secure Configuration

- Environment variable configuration
- Encrypted client secrets storage
- Secure OAuth redirect validation
- Provider token verification

## Provider-Specific Implementation

### Google Provider

```php
// Supports both OAuth flow and ID token verification
- OAuth 2.0 with OpenID Connect
- ID token validation with Google's public keys
- Access token verification via Google API
- Profile data from Google People API
```

### Facebook Provider

```php
// Facebook Graph API integration
- OAuth 2.0 flow
- Access token validation via Facebook Graph API
- Profile data extraction
- Long-lived token generation
```

### GitHub Provider

```php
// GitHub OAuth implementation
- OAuth 2.0 with required scopes
- Access token validation
- User profile via GitHub API
- Email verification for private emails
```

### Apple Provider

```php
// Advanced Apple Sign In implementation
- OAuth 2.0 with Sign In with Apple
- Custom JWT validation with ASN.1 parsing
- Private key JWT generation for client secrets
- First-time user data handling
```

## Monitoring and Troubleshooting

### Health Monitoring

Monitor extension health:

```php
use Glueful\Extensions\SocialLogin\SocialLogin;

$extension = container()->get(SocialLogin::class);
$health = $extension->getHealth();

// Returns configuration status, provider availability, etc.
```

### Common Issues

1. **OAuth Redirect Mismatch**
   - Ensure redirect URIs match exactly in provider settings
   - Use HTTPS for production environments

2. **Invalid Client Credentials**
   - Verify client ID and secret are correct
   - Check environment variable names

3. **Apple Sign In Issues**
   - Ensure private key file is readable
   - Verify Team ID and Key ID are correct
   - Check domain registration with Apple

4. **Token Validation Failures**
   - Verify system time is synchronized
   - Check internet connectivity for provider APIs
   - Ensure required PHP extensions are installed

### Debug Mode

Enable detailed logging:

```env
APP_DEBUG=true
SOCIAL_LOGIN_DEBUG=true
```

### Health Checks

```bash
# Check social login system health
curl -H "Authorization: Bearer your-token" \
     http://your-domain.com/health/social-login
```

## Migration and Integration

### Existing User Migration

Migrate existing users to social login:

```php
// Link existing users to social accounts
$existingUser = $userRepository->findByEmail($socialEmail);
if ($existingUser) {
    $socialAccountService->linkAccountToUser(
        $existingUser->getUuid(),
        $provider,
        $socialId,
        $profileData
    );
}
```

### Custom Provider Implementation

Extend the system with custom providers:

```php
use Glueful\Extensions\SocialLogin\Providers\AbstractSocialProvider;

class CustomProvider extends AbstractSocialProvider
{
    public function getAuthorizationUrl(array $scopes = []): string
    {
        // Implement OAuth authorization URL generation
    }
    
    public function validateToken(string $token): array
    {
        // Implement token validation logic
    }
    
    public function getUserProfile(string $accessToken): array
    {
        // Implement user profile retrieval
    }
}
```

## Performance Considerations

- **Connection Pooling**: HTTP clients use connection pooling for provider APIs
- **Caching**: Provider configurations and public keys are cached
- **Database Optimization**: Indexed social accounts table for fast lookups
- **Token Caching**: JWT validation results are cached to reduce API calls

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the SocialLogin extension:
- Create an issue in the repository
- Consult the Glueful documentation
- Check provider-specific documentation for OAuth setup
- Use the built-in health monitoring for diagnostics