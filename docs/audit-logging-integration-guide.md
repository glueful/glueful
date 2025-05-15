# Key Integration Areas for Audit Logging in Glueful

This document outlines the critical areas in the Glueful framework where audit logging should be integrated. These integration points ensure comprehensive security event tracking across the application.

## 1. Authentication System

### Integration Points:
- **`api/Auth/AuthenticationManager.php`**: Already has a `logAccess` method that could be enhanced to use the audit logger
- **`api/Auth/JwtAuthenticationProvider.php`**: Authentication token validation
- **`api/Controllers/AuthController.php`**: Login/logout handling
- **`api/Auth/AdminAuthenticationProvider.php`**: Admin login verification
- **`api/Auth/SessionCacheManager.php`**: Session management operations

### Events to Log:
- User login success/failure
- Admin login attempts
- Token generation and verification
- Session creation and termination
- Failed authentication attempts
- Password changes and resets

## 2. Authorization and Access Control

### Integration Points:
- **`api/Repository/PermissionRepository.php`**: Permission checks and modifications
- **`api/Repository/RoleRepository.php`**: Role assignments and changes
- **`api/Auth/AuthorizationService.php`**: Resource access decisions
- **`api/Http/Middleware/AuthenticationMiddleware.php`**: Request authorization checks

### Events to Log:
- Permission checks (particularly denied access)
- Role assignments and removals
- Permission modifications
- Administrative permission changes
- Elevation of privileges

## 3. Data Access and Manipulation

### Integration Points:
- **`api/Repository/UserRepository.php`**: User data manipulation
- **`api/Database/QueryBuilder.php`**: Add hooks for sensitive table operations
- **`api/Repository/BaseRepository.php`**: Base operations for data manipulation
- **`api/Database/QueryLogger.php`**: Already logs queries but could integrate with audit system

### Events to Log:
- Sensitive data access (PII, financial data)
- Data exports and bulk operations
- Record creation, updates, and deletion
- Queries on sensitive tables
- Data import operations

## 4. Administrative Operations

### Integration Points:
- **`extensions/Admin/AdminController.php`**: Administrative actions
- **`api/Controllers/ConfigController.php`**: System configuration
- **`api/Controllers/UserManagementController.php`**: User administration

### Events to Log:
- System setting changes
- User account management
- Bulk operations
- Administrative access to sensitive features
- System maintenance operations

## 5. Security Operations

### Integration Points:
- **`api/Auth/TokenManager.php`**: API key and token management
- **`api/Security/RateLimiter.php`**: Security controls and violations
- **`api/Security/EmailVerification.php`**: Account verification

### Events to Log:
- API key creation and rotation
- Security setting modifications
- Rate limit violations and IP blocks
- Email verification attempts
- Security alert triggers

## 6. Extension Management

### Integration Points:
- **`api/Extensions.php`**: Extension operations
- **`api/Helpers/ExtensionsManager.php`**: Extension management

### Events to Log:
- Extension installation and removal
- Extension configuration changes
- Extension permission grants
- Extension errors and failures

## 7. File System and Upload Operations

### Integration Points:
- **`api/FileSystem/FileManager.php`**: File operations
- **`api/Controllers/FileController.php`**: File uploads and access

### Events to Log:
- File uploads and downloads
- File access and sharing
- File deletion
- Permission changes on files

## 8. Social Authentication Integration

### Integration Points:
- **`extensions/SocialLogin/Providers/AbstractSocialProvider.php`**: OAuth processes
- **`extensions/SocialLogin/SocialLogin.php`**: Provider registration
- **`extensions/SocialLogin/routes.php`**: Social login endpoints

### Events to Log:
- Social login attempts
- Account linkage operations
- Provider configuration changes
- OAuth callback processing

## 9. OAuth Server Operations (Enterprise Extension)

### Integration Points:
- **`extensions/OAuthServer/Auth/OAuth/Repositories/AccessTokenRepository.php`**: Token operations
- **`extensions/OAuthServer/Auth/OAuth/Repositories/UserRepository.php`**: User verification

### Events to Log:
- Token generation and validation
- Client authorization
- Scope approvals and changes
- Token revocations

## 10. Database Schema Changes

### Integration Points:
- **`database/migrations/MigrationManager.php`**: Schema modifications
- **`api/Database/Schema/SchemaManager.php`**: Database structure changes

### Events to Log:
- Database migrations
- Schema alterations
- Data transformations
- Batch operations

## Implementation Example

For each integration point, follow this pattern:

```php
// Get the audit logger
$auditLogger = Glueful\Logging\AuditLogger::getInstance();

// Log the event with appropriate category, action, severity, and context
$auditLogger->audit(
    AuditEvent::CATEGORY_AUTH,  // Use appropriate category constant
    'specific_action_name',     // Descriptive action name
    AuditEvent::SEVERITY_INFO,  // Appropriate severity level
    [                           // Relevant context data
        'user_id' => $userId,
        'resource_id' => $resourceId,
        'ip_address' => $request->getClientIp(),
        // Additional context...
    ]
);

// Or use specialized methods for common event types
$auditLogger->authEvent('password_reset', $userId, ['ip' => $request->getClientIp()]);
$auditLogger->dataEvent('customer_export', $userId, $dataId, 'customers');
$auditLogger->configEvent('api_rate_limit_change', $adminId, 'security.rate_limits');
```
