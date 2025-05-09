# Glueful Testing

This directory contains test cases for the Glueful framework, organized into unit tests and integration tests.

## Running Tests

To run the entire test suite:

```bash
composer test
```

To run only unit tests:

```bash
composer test:unit
```

To run only integration tests:

```bash
composer test:integration
```

To run tests with coverage report:

```bash
composer test:coverage
```

## Test Structure

- `Unit/` - Contains unit tests for individual components
  - `API/` - Tests for the core API functionality
  - `Auth/` - Tests for authentication components
  - `Database/` - Tests for database components
  - `Validation/` - Tests for validation system
  - `Http/` - Tests for HTTP components
  - `Exceptions/` - Tests for exception handling
  - `Logging/` - Tests for logging components

- `Integration/` - Contains integration tests for component interactions
  - `API/` - Integration tests for API endpoints
  - `Database/` - Integration tests for database operations
  - `Extensions/` - Integration tests for extension system

- `Fixtures/` - Contains test fixtures and sample data

## Adding New Tests

1. Create test classes that extend `Tests\TestCase`
2. Follow the naming convention: `{ComponentName}Test.php`
3. Group tests logically by component and type
4. Use data providers for testing multiple variations
5. Mock external dependencies when appropriate

## Database Testing

For tests that require database operations:

1. Use SQLite in-memory database (configured in phpunit.xml)
2. Extend `Tests\DatabaseTestCase` which includes migration support
3. Use the `RefreshDatabase` trait to reset database between tests

## Environment Configuration

The testing environment uses:
- .env.testing environment file (if available)
- In-memory SQLite database for integration tests
- Disabled file and database logging
