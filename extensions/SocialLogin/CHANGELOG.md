# Changelog

All notable changes to the SocialLogin extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Twitter/X OAuth integration
- LinkedIn professional authentication
- Discord social login support
- Two-factor authentication with social providers
- Social account activity monitoring and analytics

## [0.18.0] - 2024-06-21

### Added
- **Multi-Platform OAuth Support**
  - Google OAuth 2.0 with OpenID Connect
  - Facebook Graph API integration
  - GitHub OAuth with required scopes
  - Apple Sign In with advanced JWT validation
- **Dual Authentication Flows**
  - Web-based OAuth redirect flow for traditional applications
  - Native mobile token verification for mobile apps
  - Direct token validation endpoints for SPA applications
- **Advanced Apple Sign In Integration**
  - Custom ASN.1 JWT parser for Apple's signature validation
  - Private key JWT generation for client authentication
  - Support for Apple's unique first-login-only user data
  - Proxy email handling for privacy-focused users
- **Enterprise Security Features**
  - CSRF protection with state parameter validation
  - JWT token management with Glueful's TokenManager
  - Secure OAuth redirect URI validation
  - Provider token verification and expiry handling
- **Database Integration**
  - Comprehensive social accounts table with foreign keys
  - User-social account relationship management
  - Profile data synchronization and storage
  - Unique constraints to prevent duplicate associations

### Enhanced
- **User Management System**
  - Automatic user registration from social profiles
  - Intelligent account linking via email matching
  - Profile synchronization with configurable options
  - User creation with social provider metadata
- **Provider Abstraction**
  - Abstract provider pattern for consistent implementation
  - Factory pattern for dynamic provider instantiation
  - Standardized error handling across providers
  - Configurable scope management per provider
- **API Architecture**
  - RESTful endpoints with comprehensive OpenAPI documentation
  - Consistent response formats across all providers
  - Proper HTTP status code handling
  - Detailed error responses with debugging information

### Security
- Implemented comprehensive CSRF protection for OAuth flows
- Added secure token storage and validation mechanisms
- Enhanced provider token verification with timeout handling
- Secure configuration management with environment variables

### Performance
- Provider configurations and public keys are cached for performance
- HTTP clients use connection pooling for provider API calls
- Database queries optimized with proper indexing
- JWT validation results cached to reduce external API calls

### Developer Experience
- Complete API documentation with request/response examples
- Comprehensive error handling with detailed error messages
- Health monitoring endpoints for system diagnostics
- Extensive configuration options for customization

## [0.17.0] - 2024-04-30

### Added
- **Core OAuth Infrastructure**
  - OAuth 2.0 flow implementation for Google and Facebook
  - Basic GitHub OAuth integration
  - Initial Apple Sign In support
- **User Account Management**
  - Basic user registration from social profiles
  - Simple account linking functionality
  - Profile data extraction and storage
- **Database Foundation**
  - Social accounts table creation migration
  - Basic foreign key relationships
  - User-social account association logic

### Enhanced
- **Provider Management**
  - Configuration-based provider enabling/disabling
  - Environment variable configuration support
  - Basic error handling for failed authentications
- **Security Foundation**
  - Basic OAuth state parameter validation
  - Initial token verification implementation
  - Secure redirect URI handling

### Fixed
- OAuth callback URL handling inconsistencies
- Token expiry validation edge cases
- User profile data extraction errors

## [0.16.0] - 2024-03-15

### Added
- **Google OAuth Integration**
  - Complete Google OAuth 2.0 implementation
  - ID token verification with Google's public keys
  - User profile data extraction from Google People API
- **Facebook OAuth Integration**
  - Facebook Login integration with Graph API
  - Access token validation and user profile retrieval
  - Long-lived token support for extended sessions
- **Basic Security Features**
  - OAuth state parameter generation and validation
  - Basic CSRF protection for authentication flows
  - Secure token storage in session management

### Infrastructure
- Extension service provider registration
- Route definitions for OAuth endpoints
- Basic configuration management system
- Initial database migration for social accounts

## [0.15.0] - 2024-02-20

### Added
- **Project Foundation**
  - Extension scaffolding and structure
  - Basic OAuth flow architecture
  - Initial provider abstraction layer
  - Core service provider setup
- **GitHub OAuth Integration**
  - GitHub OAuth application support
  - User profile and email retrieval
  - Basic scope management for GitHub API
- **Configuration System**
  - Environment variable configuration
  - Provider-specific configuration management
  - Basic validation for OAuth credentials

### Infrastructure
- Extension metadata and composer configuration
- Initial testing framework setup
- Basic development workflow establishment
- Documentation foundation

## [0.14.0] - 2024-01-25

### Added
- Initial project setup and structure
- Basic extension framework integration
- Core dependency injection configuration
- Initial OAuth research and planning

---

## Release Notes

### Version 0.18.0 Highlights

This major release establishes the SocialLogin extension as an enterprise-grade OAuth authentication solution. Key improvements include:

- **Complete Multi-Provider Support**: Full implementation for Google, Facebook, GitHub, and Apple
- **Dual Authentication Flows**: Support for both web applications and native mobile apps
- **Advanced Apple Integration**: Custom ASN.1 parser and comprehensive Sign In with Apple support
- **Enterprise Security**: CSRF protection, JWT validation, and secure token management
- **Developer Experience**: Comprehensive API documentation and health monitoring

### Upgrade Notes

When upgrading to 0.18.0:
1. Update your OAuth provider configurations in environment variables
2. Run the database migration to create the social_accounts table
3. Test both web and mobile authentication flows
4. Update your frontend applications to use the new API endpoints
5. Configure Apple Sign In if using iOS applications

### Breaking Changes

- OAuth callback URLs have been standardized (update your provider configurations)
- Database schema now requires the social_accounts table migration
- Some configuration keys have been reorganized for consistency
- API responses now follow a standardized format across all providers

### Migration Guide

#### Database Migration
Run the migration to create the required table:
```bash
php glueful migrate run
```

#### Configuration Migration
Update your environment variables to the new format:

```env
# Google (updated format)
GOOGLE_CLIENT_ID=your-google-client-id.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/social/google/callback

# Apple (new provider)
APPLE_CLIENT_ID=com.yourdomain.services.id
APPLE_CLIENT_SECRET=/path/to/AuthKey_XXXXXXXXXX.p8
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_REDIRECT_URI=https://yourdomain.com/auth/social/apple/callback
```

#### API Integration
Update your frontend code to use the new endpoints:

```javascript
// Web OAuth Flow (unchanged)
window.location.href = '/auth/social/google';

// New: Native Mobile Flow
const response = await fetch('/auth/social/google', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    id_token: googleIdToken,
    access_token: googleAccessToken
  })
});
```

### Apple Sign In Setup

For Apple Sign In, you'll need:
1. Apple Developer account with Services ID configured
2. Private key (.p8 file) for JWT generation
3. Domain verification with Apple
4. Proper redirect URI configuration

### Security Considerations

- All OAuth flows now include CSRF protection
- Provider tokens are validated against official APIs
- JWT tokens use Glueful's secure TokenManager
- Social account data is properly encrypted in storage

### Performance Improvements

- Provider public keys are cached for faster validation
- Connection pooling reduces API call overhead
- Database queries are optimized with proper indexing
- JWT validation results are cached to minimize external requests

---

**Full Changelog**: https://github.com/glueful/extensions/compare/social-login-v0.17.0...social-login-v0.18.0