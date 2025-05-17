# Security Tests for Glueful

This directory contains integration tests for the security components of Glueful.

## Running the Tests

### Running Security Tests Only

To run only the security tests, use:

```bash
cd /Users/michaeltawiahsowah/Sites/localhost/glueful
vendor/bin/phpunit --bootstrap tests/bootstrap-security.php tests/Integration/Security
```

### Test Structure

- `tests/Integration/Security/`: Contains integration tests for security components
- `tests/Mocks/`: Contains mock classes for testing
- `tests/bootstrap-security.php`: Bootstrap file for security tests

## Mock Classes

The tests use mock classes to avoid modifying the original classes and to isolate the tests:

- `MockCacheEngine`: Mocks the CacheEngine class
- `MockAuditLogger`: Mocks the AuditLogger class
- `MockRateLimiterDistributor`: Mocks the RateLimiterDistributor class
- `MockRateLimiter`: Mocks the RateLimiter class
- `MockRateLimiterRule`: Mocks the RateLimiterRule class

## Testing Approach

The integration tests focus on testing the AdaptiveRateLimiter's functionality without modifying the original class. This is achieved by:

1. Using mock classes for all dependencies
2. Injecting the mocks using a custom autoloader
3. Testing various scenarios including:
   - Basic rate limiting
   - Behavior scoring
   - Rule-based rate limiting
   - Behavior profile updates