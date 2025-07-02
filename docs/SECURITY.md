# Glueful Security Guide

## Overview

Glueful provides enterprise-grade security features that are fully implemented and production-ready. This guide covers the comprehensive security system that protects your application from common threats and vulnerabilities.

## Table of Contents

- [Security Architecture](#security-architecture)
- [Vulnerability Scanner](#vulnerability-scanner)
- [Authentication & Authorization](#authentication--authorization)
- [Rate Limiting System](#rate-limiting-system)
- [Session Security](#session-security)
- [Security Middleware](#security-middleware)
- [CSRF Protection](#csrf-protection)
- [Security Headers](#security-headers)
- [Emergency Lockdown](#emergency-lockdown)
- [Security Monitoring](#security-monitoring)
- [Security Configuration](#security-configuration)
- [Security Commands](#security-commands)
- [Production Security](#production-security)

## Security Architecture

### Multi-Layer Defense

Glueful implements a comprehensive security architecture with multiple layers of protection:

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                        │
│  • CSRF Protection  • Input Validation  • Authorization   │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Framework Layer                          │
│  • Rate Limiting  • Security Headers  • Authentication    │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                  Infrastructure Layer                       │
│  • Emergency Lockdown  • Vulnerability Scanner  • Audit   │
└─────────────────────────────────────────────────────────────┘
```

### Core Security Components

- **Vulnerability Scanner**: Automated security vulnerability detection
- **Adaptive Rate Limiting**: Multi-dimensional request limiting with behavioral analysis
- **JWT Authentication**: Dual-layer session storage (database + cache)
- **CSRF Protection**: Double-submit pattern with configurable exemptions
- **Security Headers**: Comprehensive security header middleware
- **Emergency Lockdown**: Granular severity-based access control
- **Session Analytics**: Advanced session monitoring and anomaly detection

## Vulnerability Scanner

Glueful includes a comprehensive vulnerability scanner that analyzes code, dependencies, and configuration for security issues.

### Scanner Capabilities

The vulnerability scanner detects:

1. **Code Vulnerabilities**
   - SQL injection patterns
   - Cross-site scripting (XSS)
   - Path traversal vulnerabilities
   - Command injection
   - Insecure direct object references

2. **Dependency Vulnerabilities**
   - Known CVEs in Composer packages
   - Outdated dependencies with security issues
   - Vulnerable JavaScript dependencies

3. **Configuration Security**
   - Environment configuration issues
   - File permission problems
   - Missing security headers
   - Debug mode in production

### Running Security Scans

```bash
# Full vulnerability scan
php glueful security:scan

# Scan specific component types
php glueful security:scan --type=code
php glueful security:scan --type=dependencies
php glueful security:scan --type=config

# Generate detailed reports
php glueful security:vulnerabilities --format=json
php glueful security:vulnerabilities --export=csv
```

### Scanner Output Example

```bash
$ php glueful security:scan

Glueful Security Scanner
=======================

✓ Scanning code for vulnerabilities...
✓ Checking dependencies...
✓ Validating configuration...

Results:
========
[HIGH] Potential SQL injection in UserController
  File: api/Controllers/UserController.php:45
  Issue: User input not properly sanitized
  Recommendation: Use parameterized queries

[MEDIUM] Outdated dependency detected
  Package: guzzlehttp/guzzle 6.5.2
  Issue: Known security vulnerability CVE-2022-29248
  Recommendation: Update to version 7.4.3+

[LOW] Missing security header
  Issue: X-Content-Type-Options not configured
  Recommendation: Enable in SecurityHeadersMiddleware

Summary:
- High: 1 issue
- Medium: 1 issue  
- Low: 1 issue
- Security Score: 85/100
```

### Configuration

```env
# Vulnerability Scanner Settings
VULNERABILITY_SCANNER_ENABLED=true
SCANNER_AUTO_EXPORT=true
SCANNER_HISTORICAL_TRACKING=true
SCANNER_REPORT_FORMAT=json
```

## Authentication & Authorization

### JWT Authentication with Dual-Layer Storage

Glueful implements JWT authentication with both database and cache storage for optimal performance and reliability.

#### TokenStorageService

```php
use Glueful\Auth\TokenStorageService;

$tokenStorage = container()->get(TokenStorageService::class);

// Store session with metadata
$sessionData = [
    'uuid' => $userUuid,
    'username' => $username,
    'email' => $email
];

$tokens = [
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken
];

$success = $tokenStorage->storeSession($sessionData, $tokens);

// Retrieve session
$session = $tokenStorage->getSessionByAccessToken($accessToken);

// Validate session
$isValid = $tokenStorage->isSessionValid($accessToken);

// Session cleanup
$cleanedCount = $tokenStorage->cleanupExpiredSessions();
```

#### Session Analytics

```php
use Glueful\Auth\SessionAnalytics;

$analytics = container()->get(SessionAnalytics::class);

// Get session metrics
$metrics = $analytics->getSessionMetrics($userUuid);

// Analyze session patterns
$patterns = $analytics->analyzeSessionPatterns($userUuid);

// Security assessment
$riskScore = $analytics->calculateRiskScore($sessionId);
```

#### Authentication Configuration

```env
# JWT Configuration
JWT_KEY=your-secure-256-bit-key
ACCESS_TOKEN_LIFETIME=900    # 15 minutes
REFRESH_TOKEN_LIFETIME=604800 # 7 days
SESSION_LIFETIME=86400       # 24 hours

# Session Storage
SESSION_CACHE_ENABLED=true
SESSION_CACHE_TTL=3600
SESSION_DB_ENABLED=true
```

## Rate Limiting System

Glueful implements sophisticated rate limiting with adaptive behavior and multiple dimensions.

### Adaptive Rate Limiting

The rate limiter analyzes user behavior and adjusts limits dynamically:

```env
# Rate Limiting Configuration
RATE_LIMITING_ENABLED=true
ADAPTIVE_RATE_LIMITING=true
RATE_LIMIT_DRIVER=redis

# Multiple Dimensions
IP_RATE_LIMIT_MAX=100        # per minute
USER_RATE_LIMIT_MAX=1000     # per hour
ENDPOINT_RATE_LIMIT_MAX=50   # per minute

# Adaptive Behavior
RATE_LIMIT_BEHAVIOR_PROFILING=true
RATE_LIMIT_MACHINE_LEARNING=false  # Statistical analysis used instead
RATE_LIMIT_BURST_ALLOWANCE=2.0
```

### Rate Limiting in Action

The rate limiter automatically:
- Tracks request patterns per IP, user, and endpoint
- Applies adaptive penalties for suspicious behavior
- Integrates with the security event system
- Provides detailed rate limit headers in responses

### Rate Limit Headers

```http
X-Rate-Limit-Limit: 100
X-Rate-Limit-Remaining: 95
X-Rate-Limit-Reset: 1640995200
Retry-After: 60
```

## Session Security

### Session Monitoring

Glueful provides comprehensive session security monitoring:

```php
use Glueful\Auth\SessionAnalytics;

$analytics = container()->get(SessionAnalytics::class);

// Monitor session for anomalies
$anomalies = $analytics->detectAnomalies($sessionId);

// Get session risk assessment
$riskAssessment = $analytics->assessSessionRisk($sessionId);

// Track session behavior
$behaviorProfile = $analytics->getSessionBehaviorProfile($userId);
```

### Session Configuration

```env
# Session Security
SESSION_ANALYTICS_ENABLED=true
SESSION_ANOMALY_DETECTION=true
SESSION_RISK_SCORING=true
SESSION_BEHAVIOR_TRACKING=true

# Security Thresholds
SUSPICIOUS_LOGIN_THRESHOLD=3
SESSION_HIJACK_DETECTION=true
UNUSUAL_LOCATION_ALERTS=true
```

## Security Middleware

Glueful includes comprehensive security middleware that's automatically applied:

### Available Middleware

1. **AuthenticationMiddleware** - JWT token validation
2. **AdminPermissionMiddleware** - Admin access control
3. **RateLimiterMiddleware** - Request rate limiting
4. **SecurityHeadersMiddleware** - Security headers
5. **CSRFMiddleware** - CSRF protection

### Middleware Stack

The security middleware stack is automatically configured based on your environment and security settings.

## CSRF Protection

### Double-Submit Pattern

Glueful implements CSRF protection using the double-submit cookie pattern:

```php
// CSRF token generation (automatic)
$token = csrf_token();

// Validation (automatic in middleware)
// Tokens are validated from:
// - HTTP headers (X-CSRF-TOKEN)
// - Form fields (_token)
// - JSON body (token)
```

### CSRF Configuration

```env
# CSRF Protection
CSRF_PROTECTION_ENABLED=true
CSRF_TOKEN_LIFETIME=3600
CSRF_COOKIE_NAME=csrf_token
CSRF_HEADER_NAME=X-CSRF-TOKEN

# Route Exemptions
CSRF_EXEMPT_ROUTES=api/webhooks,api/public
```

### CSRF in Forms

```html
<!-- Automatic token injection in forms -->
<form method="POST" action="/submit">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <!-- form fields -->
</form>
```

## Security Headers

### Comprehensive Header Protection

The SecurityHeadersMiddleware automatically adds security headers:

```php
// Headers automatically applied:
// - Content-Security-Policy
// - X-XSS-Protection: 1; mode=block
// - X-Content-Type-Options: nosniff
// - X-Frame-Options: DENY
// - Referrer-Policy: strict-origin-when-cross-origin
// - Strict-Transport-Security (HTTPS only)
// - Permissions-Policy
```

### Security Headers Configuration

```env
# Security Headers
SECURITY_HEADERS_ENABLED=true
HSTS_MAX_AGE=31536000
CSP_ENABLED=true
CSP_REPORT_URI=/api/csp-report
FRAME_OPTIONS=DENY
```

### Content Security Policy

```env
# CSP Configuration
CSP_DEFAULT_SRC="'self'"
CSP_SCRIPT_SRC="'self' 'unsafe-inline'"
CSP_STYLE_SRC="'self' 'unsafe-inline'"
CSP_IMG_SRC="'self' data: https:"
CSP_FONT_SRC="'self'"
CSP_CONNECT_SRC="'self'"
CSP_MEDIA_SRC="'self'"
CSP_OBJECT_SRC="'none'"
```

## Emergency Lockdown

### Granular Lockdown System

Glueful includes an emergency lockdown system with multiple severity levels:

```bash
# Enable lockdown with different severity levels
php glueful security:lockdown --severity=low
php glueful security:lockdown --severity=medium
php glueful security:lockdown --severity=high
php glueful security:lockdown --severity=critical

# Disable lockdown
php glueful security:lockdown --disable
```

### Lockdown Severity Levels

1. **Low**: Restrict non-essential endpoints
2. **Medium**: Allow only core functionality
3. **High**: Emergency access only
4. **Critical**: Complete lockdown (admin access only)

### Lockdown Configuration

```env
# Emergency Lockdown
LOCKDOWN_ENABLED=false
LOCKDOWN_SEVERITY=medium
LOCKDOWN_AUTO_RECOVERY=true
LOCKDOWN_RECOVERY_TIME=3600  # 1 hour

# IP Blocking
LOCKDOWN_ENABLE_IP_BLOCKING=true
LOCKDOWN_IP_BLOCK_THRESHOLD=10
LOCKDOWN_IP_BLOCK_DURATION=1800

# Notifications
LOCKDOWN_WEBHOOK_URL=https://alerts.company.com/webhook
LOCKDOWN_EMAIL_ALERTS=security@company.com
```

### Lockdown Status Check

```bash
# Check lockdown status
php glueful security:check

# Example output:
Security Status: ACTIVE LOCKDOWN
Severity Level: HIGH
Lockdown Reason: Emergency security incident
Auto-Recovery: Enabled (expires in 45 minutes)
Blocked IPs: 3 addresses
Restricted Endpoints: 15 routes
```

## Security Monitoring

### Real-Time Security Events

Glueful dispatches security events that you can listen to:

```php
// Available security events:
// - CSRFViolationEvent
// - RateLimitExceededEvent  
// - SessionCreatedEvent
// - SessionDestroyedEvent
// - SecurityScanCompletedEvent
```

### Event Listeners

```php
use Glueful\Events\Security\RateLimitExceededEvent;

$dispatcher->addListener(RateLimitExceededEvent::class, function($event) {
    // Custom security response
    if ($event->isSevereViolation()) {
        // Enable emergency lockdown
        $lockdownService->enableLockdown('high', 'Rate limit abuse detected');
    }
});
```

## Security Configuration

### Main Security Configuration

```php
// config/security.php
return [
    'vulnerability_scanner' => [
        'enabled' => env('VULNERABILITY_SCANNER_ENABLED', true),
        'auto_export' => env('SCANNER_AUTO_EXPORT', true),
        'historical_tracking' => env('SCANNER_HISTORICAL_TRACKING', true),
    ],
    
    'rate_limiting' => [
        'enabled' => env('RATE_LIMITING_ENABLED', true),
        'adaptive' => env('ADAPTIVE_RATE_LIMITING', true),
        'behavior_profiling' => env('RATE_LIMIT_BEHAVIOR_PROFILING', true),
    ],
    
    'csrf' => [
        'enabled' => env('CSRF_PROTECTION_ENABLED', true),
        'token_lifetime' => env('CSRF_TOKEN_LIFETIME', 3600),
        'exempt_routes' => explode(',', env('CSRF_EXEMPT_ROUTES', '')),
    ],
    
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'hsts_max_age' => env('HSTS_MAX_AGE', 31536000),
        'csp_enabled' => env('CSP_ENABLED', true),
    ],
];
```

### Lockdown Configuration

```php
// config/lockdown.php
return [
    'enabled' => env('LOCKDOWN_ENABLED', false),
    'severity' => env('LOCKDOWN_SEVERITY', 'medium'),
    'auto_recovery' => env('LOCKDOWN_AUTO_RECOVERY', true),
    'recovery_time' => env('LOCKDOWN_RECOVERY_TIME', 3600),
    
    'ip_blocking' => [
        'enabled' => env('LOCKDOWN_ENABLE_IP_BLOCKING', true),
        'threshold' => env('LOCKDOWN_IP_BLOCK_THRESHOLD', 10),
        'duration' => env('LOCKDOWN_IP_BLOCK_DURATION', 1800),
    ],
    
    'notifications' => [
        'webhook_url' => env('LOCKDOWN_WEBHOOK_URL'),
        'email_alerts' => env('LOCKDOWN_EMAIL_ALERTS'),
    ],
];
```

## Security Commands

### Available Security Commands

```bash
# Vulnerability Management
php glueful security:scan                    # Run vulnerability scan
php glueful security:vulnerabilities         # List vulnerabilities
php glueful security:check                   # Security health check

# Lockdown Management  
php glueful security:lockdown                # Manage emergency lockdown
php glueful security:lockdown --severity=high
php glueful security:lockdown --disable

# Authentication & Sessions
php glueful security:revoke-tokens           # Revoke user tokens
php glueful security:reset-password          # Reset user passwords

# Reporting
php glueful security:report                  # Generate security report
```

### Security Command Examples

```bash
# Comprehensive security check
$ php glueful security:check

Security Health Check
====================
✓ Vulnerability Scanner: Active
✓ Rate Limiting: Enabled (Adaptive)
✓ CSRF Protection: Active
✓ Security Headers: Configured
✓ Session Analytics: Active
✗ Emergency Lockdown: Disabled
⚠ SSL Certificate: Expires in 30 days

Overall Security Score: 92/100
```

```bash
# Emergency lockdown activation
$ php glueful security:lockdown --severity=high --reason="Security incident"

Emergency Lockdown Activated
============================
Severity: HIGH
Reason: Security incident
Restricted Endpoints: 15 routes
Auto-Recovery: Enabled (60 minutes)
IP Blocking: Active
Notifications: Sent to security team
```

## Production Security

### Production Security Validation

Glueful includes production security validation to ensure your application is properly secured:

```bash
# Validate production security
php glueful security:check --environment=production

# Example output:
Production Security Validation
==============================
✓ Environment: production
✓ Debug Mode: Disabled
✓ SSL/HTTPS: Enforced
✓ Security Headers: Active
✓ Rate Limiting: Configured
✓ CSRF Protection: Enabled
✓ JWT Keys: Secure (256-bit)
✓ Database: SSL Enabled
✗ Vulnerability Scanner: Not scheduled
⚠ Session Timeout: Consider shorter timeout

Security Score: 88/100

Recommendations:
1. Schedule daily vulnerability scans
2. Reduce session timeout for production
3. Enable additional rate limiting dimensions
```

### Production Security Checklist

Before deploying to production, ensure:

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Strong JWT keys generated (`php glueful generate:key`)
- [ ] SSL/HTTPS enforced
- [ ] Security headers enabled
- [ ] CSRF protection active
- [ ] Rate limiting configured
- [ ] Vulnerability scanner scheduled
- [ ] Emergency lockdown configured
- [ ] Security monitoring enabled
- [ ] Database SSL enabled

### Environment-Specific Security

```env
# Production Security Settings
APP_ENV=production
APP_DEBUG=false

# Strict Security
SECURITY_LEVEL=strict
RATE_LIMITING_STRICT_MODE=true
CSRF_PROTECTION_STRICT=true
SECURITY_HEADERS_STRICT=true

# Monitoring
VULNERABILITY_SCANNER_SCHEDULE=daily
SECURITY_MONITORING_ENABLED=true
SECURITY_ALERTS_EMAIL=security@company.com
```

## Security Best Practices

### Development Security

1. **Use the vulnerability scanner regularly**
   ```bash
   php glueful security:scan
   ```

2. **Test security configurations**
   ```bash
   php glueful security:check
   ```

3. **Monitor security events**
   ```php
   // Listen for security events
   $dispatcher->addListener(SecurityEvent::class, $handler);
   ```

### Production Security

1. **Enable all security features**
   ```env
   SECURITY_LEVEL=strict
   ```

2. **Schedule regular security scans**
   ```bash
   # Add to cron
   0 2 * * * cd /path/to/app && php glueful security:scan
   ```

3. **Monitor security metrics**
   ```bash
   php glueful security:report --format=json
   ```

4. **Configure emergency procedures**
   ```bash
   # Test lockdown procedures
   php glueful security:lockdown --test
   ```

## Summary

Glueful provides a comprehensive, production-ready security framework that includes:

- **Automated vulnerability scanning** with detailed reporting
- **Adaptive rate limiting** with behavioral analysis
- **Robust authentication** with dual-layer session storage
- **Comprehensive CSRF protection** with configurable exemptions
- **Security headers middleware** with modern security policies
- **Emergency lockdown system** with granular severity levels
- **Real-time security monitoring** with event-driven responses
- **Production security validation** with automated checks

All security features are fully implemented, tested, and ready for production use. The security system is designed to provide enterprise-grade protection while maintaining ease of use and configuration flexibility.