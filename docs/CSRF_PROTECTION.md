# CSRF Protection Implementation Guide

This document explains how to use the CSRF (Cross-Site Request Forgery) protection middleware in Glueful.

## Overview

The `CSRFMiddleware` protects against CSRF attacks by validating tokens for state-changing HTTP methods (POST, PUT, PATCH, DELETE). It provides:

- Cryptographically secure token generation
- Session-based token storage with cache fallback
- Multiple token submission methods (header, form field, JSON)
- Configurable exempt routes
- Integration with existing authentication system

## Quick Setup

### 1. Add Middleware to Your Application

```php
// In your middleware stack or route configuration
use Glueful\Http\Middleware\CSRFMiddleware;

// Basic usage
$app->add(new CSRFMiddleware());

// With API route exemptions
$app->add(CSRFMiddleware::withApiExemptions());

// Custom configuration
$app->add(new CSRFMiddleware(
    exemptRoutes: ['api/webhooks/*', 'api/public/*'],
    tokenLifetime: 3600, // 1 hour
    useDoubleSubmit: false,
    enabled: true
));
```

### 2. Include CSRF Token in Forms

```html
<!-- HTML Forms -->
<form method="POST" action="/api/users">
    <?= \Glueful\Helpers\Utils::csrfField($request) ?>
    <input type="text" name="username" required>
    <button type="submit">Create User</button>
</form>
```

### 3. Include CSRF Token in AJAX Requests

```javascript
// Get CSRF token data
fetch('/api/csrf-token')
    .then(response => response.json())
    .then(data => {
        // Store token for subsequent requests
        window.csrfToken = data.token;
        window.csrfHeader = data.header;
    });

// Use in AJAX requests
fetch('/api/users', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.csrfToken
    },
    body: JSON.stringify({
        username: 'john_doe',
        email: 'john@example.com'
    })
});
```

## Configuration Options

### Constructor Parameters

- `exemptRoutes` (array): Routes to exempt from CSRF protection
- `tokenLifetime` (int): Token lifetime in seconds (default: 3600)
- `useDoubleSubmit` (bool): Enable double-submit cookie pattern (default: false)
- `enabled` (bool): Whether CSRF protection is enabled (default: true)

### Environment Variables

You can disable CSRF protection via environment:

```env
# .env
CSRF_PROTECTION_ENABLED=true
CSRF_TOKEN_LIFETIME=3600
```

## Token Submission Methods

The middleware accepts CSRF tokens via:

1. **HTTP Header** (recommended for AJAX): `X-CSRF-Token`
2. **Form Field**: `_token`
3. **Query Parameter**: `_token` (use with caution)
4. **JSON Body**: `{"_token": "your-token"}`

## Helper Functions

### Utils::csrfToken(Request $request)
Returns the current CSRF token:

```php
$token = \Glueful\Helpers\Utils::csrfToken($request);
```

### Utils::csrfField(Request $request)
Returns HTML hidden input field:

```php
echo \Glueful\Helpers\Utils::csrfField($request);
// Output: <input type="hidden" name="_token" value="abc123...">
```

### Utils::csrfTokenData(Request $request)
Returns token data for JavaScript:

```php
$tokenData = \Glueful\Helpers\Utils::csrfTokenData($request);
// Returns: ['token' => '...', 'header' => 'X-CSRF-Token', 'field' => '_token', 'expires_at' => timestamp]
```

## Creating a CSRF Token Endpoint

Add this to your routes to provide tokens for JavaScript:

```php
// routes/api.php
use Glueful\Http\Response;
use Glueful\Helpers\Utils;

Router::get('/csrf-token', function($params, $request) {
    return Response::ok(Utils::csrfTokenData($request));
});
```

## Exempt Routes

Common routes to exempt from CSRF protection:

```php
$exemptRoutes = [
    'api/auth/login',           // Login endpoint
    'api/auth/register',        // Registration endpoint
    'api/webhooks/*',           // Webhook endpoints
    'api/public/*',             // Public API endpoints
    'api/uploads/temp/*'        // Temporary upload endpoints
];

$middleware = new CSRFMiddleware($exemptRoutes);
```

## Security Considerations

### Token Security
- Tokens are cryptographically secure (32 hex characters)
- Uses constant-time comparison to prevent timing attacks
- Tokens expire after configured lifetime

### Session Handling
- Tokens are tied to user sessions when authenticated
- Anonymous sessions use IP + User-Agent fingerprint
- Cache-based storage for performance

### Double-Submit Cookie Pattern
Optional enhanced security:

```php
$middleware = new CSRFMiddleware(
    useDoubleSubmit: true  // Enables cookie-based validation
);
```

## Error Handling

CSRF validation failures throw `SecurityException` with HTTP 419 status:

```php
try {
    // Process request
} catch (\Glueful\Exceptions\SecurityException $e) {
    if ($e->getData()['error_code'] === 'CSRF_TOKEN_MISMATCH') {
        // Handle CSRF failure
        return Response::error('Please refresh and try again', 419);
    }
}
```

## Testing

### Unit Tests
```php
public function testCSRFTokenGeneration()
{
    $request = new Request();
    $middleware = new CSRFMiddleware();
    
    $token = $middleware->generateToken($request);
    $this->assertNotEmpty($token);
    $this->assertEquals(32, strlen($token));
}
```

### Integration Tests
```php
public function testCSRFProtection()
{
    // Test that POST without token fails
    $response = $this->post('/api/users', ['username' => 'test']);
    $this->assertEquals(419, $response->getStatusCode());
    
    // Test that POST with valid token succeeds
    $token = $this->getCSRFToken();
    $response = $this->post('/api/users', [
        'username' => 'test',
        '_token' => $token
    ]);
    $this->assertEquals(201, $response->getStatusCode());
}
```

## Best Practices

1. **Always include CSRF tokens** in forms and AJAX requests
2. **Use HTTPS** to prevent token interception
3. **Set appropriate token lifetime** (balance security vs. UX)
4. **Exempt only necessary routes** from CSRF protection
5. **Handle CSRF failures gracefully** with user-friendly messages
6. **Test CSRF protection** in your application tests

## Troubleshooting

### Common Issues

**Token Mismatch Errors**:
- Verify token is included in request
- Check token hasn't expired
- Ensure consistent session handling

**Missing Tokens**:
- Verify middleware is properly registered
- Check exempt routes configuration
- Ensure token generation for safe methods

**AJAX Issues**:
- Include `X-CSRF-Token` header
- Get fresh token from `/csrf-token` endpoint
- Handle token expiration in JavaScript

### Debug Mode

Enable debug logging to troubleshoot CSRF issues:

```php
// In development only
$middleware = new CSRFMiddleware(
    enabled: env('APP_ENV') === 'production'
);
```