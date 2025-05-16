# Key Integration Areas for Audit Logging in Glueful

This document outlines the critical areas in the Glueful framework where audit logging should be integrated. These integration points ensure comprehensive security event tracking across the application.

> **IMPORTANT: Preventing Recursion**  
> When implementing audit logging, it is crucial to prevent recursion issues where the act of writing an audit log generates additional audit events. To prevent this, the audit logger implementation uses a special non-audited execution path for its internal operations. Always use the provided audit logging methods rather than creating custom solutions that might cause recursion.

## 1. Authentication System

### Integration Points:
- **`api/Auth/AuthenticationManager.php`**: Already has a `logAccess` method that could be enhanced to use the audit logger
- **`api/Auth/JwtAuthenticationProvider.php`**: Authentication token validation
- **`api/Controllers/AuthController.php`**: Login/logout handling
- **`api/Auth/AdminAuthenticationProvider.php`**: Admin login verification
- **`api/Auth/SessionCacheManager.php`**: Session management operations

> **Anti-Recursion Note**: Ensure that the audit logger itself is authenticated without triggering recursive authentication logs.

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

> **Anti-Recursion Note**: Implement a special execution context for audit operations that bypasses standard authorization checks when writing audit logs.

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

> **Anti-Recursion Note**: Configure QueryLogger to exclude audit_logs and audit_entities tables from audit logging to prevent recursion loops.

### Events to Log:
- Sensitive data access (PII, financial data)
- Data exports and bulk operations
- Record creation, updates, and deletion
- Queries on sensitive tables (except audit tables)
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

> **Anti-Recursion Note**: When creating or modifying audit log tables themselves, use a special migration path that does not trigger audit logging.

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

## Anti-Recursion Patterns

To prevent recursion in the audit logging system, follow these guidelines:

### 1. Exclude Audit Tables from Query Logging

```php
// In QueryLogger or QueryBuilder, add exclusion for audit tables
$excludedTables = ['audit_logs', 'audit_entities']; 
if (in_array($tableName, $excludedTables)) {
    // Skip audit logging for operations on audit tables
    return;
}
```

### 2. Use Non-Audited Database Connection

```php
// When AuditLogger itself needs to write to the database
$nonAuditedConnection = Connection::getConnectionWithoutHooks();
$nonAuditedConnection->table('audit_logs')->insert($logData);
```

### 3. Prevent Authentication/Authorization Recursion

```php
// In authentication or authorization checks
$isAuditLoggerOperation = ($context['source'] ?? null) === 'audit_logger';
if ($isAuditLoggerOperation) {
    // Skip additional logging for operations originating from the audit logger itself
    return $allowAccess;
}
```

### 4. Use Transaction Flags

```php
// When starting a transaction that may include audit operations
$connection->beginTransaction(['skip_audit' => true]);
try {
    // Perform operations including audit logging
    $connection->commit();
} catch (Exception $e) {
    $connection->rollback();
    throw $e;
}
```

### 5. Detect Recursion with Context Tracking

```php
class AuditLogger {
    // Static flag to prevent recursive calls
    private static bool $isLogging = false;
    
    public function audit(...$params) {
        // Prevent recursive logging
        if (self::$isLogging) {
            return; // Skip logging if already in a logging operation
        }
        
        try {
            self::$isLogging = true;
            // Perform actual audit logging...
        } finally {
            self::$isLogging = false; // Always reset flag even if exceptions occur
        }
    }
}
```

### 6. Use Buffered Logging

```php
// Instead of direct writes, buffer audit logs in memory
$auditLogger->buffer('auth_event', $context);

// Later flush all buffered logs in a single non-recursive operation
register_shutdown_function(function() use ($auditLogger) {
    $auditLogger->flushBufferedLogs();
});
```

## Conclusion: Building a Non-Recursive Audit System

Creating a robust audit logging system requires careful design to prevent recursive logging loops. The key principles to remember are:

1. **Separation of Concerns**: Keep audit log writing operations isolated from the systems they monitor
2. **Special Execution Paths**: Use non-audited connections and context flags when the audit logger itself operates
3. **Table Exclusion**: Always exclude audit-related tables from being logged in the standard logging pipeline
4. **Buffering Strategies**: Consider buffering logs in memory and writing them in bulk to reduce potential for recursion
5. **Context Tracking**: Maintain execution context to detect and prevent recursive logging calls

By implementing these patterns consistently across integration points, the Glueful audit logging system can maintain comprehensive coverage without suffering from recursion issues that could impact performance or storage.
