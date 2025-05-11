# Areas in Glueful that Need Unit Tests

## 1. Core Framework Components

### API Class ✅
- **Test initialization sequence**: Test that `API::init()` correctly initializes core components
- **Test error handling**: Verify proper exception handling in `processRequest()`
- **Test request lifecycle**: Ensure proper sequence of middleware execution

### Router ✅
- **Test route registration**: Verify routes are properly registered with methods (GET, POST, PUT, etc.)
- **Test route grouping**: Confirm nested route groups function correctly
- **Test middleware execution**: Validate middleware pipeline works in the expected sequence
- **Test route parameter extraction**: Ensure route parameters are correctly extracted and passed
- **Test auth requirements**: Verify routes with `requiresAuth` and `requiresAdminAuth` are protected

## 2. Database Layer ✅

### Connection Class ✅
- **Test connection pooling**: Verify connections are reused properly
- **Test multiple database engines**: Ensure MySQL, PostgreSQL, and SQLite drivers work correctly
- **Test driver selection**: Check that correct driver is loaded based on configuration
- **Test schema manager integration**: Validate schema operations work as expected

### QueryBuilder ✅
- **Test CRUD operations**: Verify basic insert, select, update, and delete operations
- **Test complex query building**: Test joins, where clauses, grouping, ordering, etc.
- **Test prepared statements**: Ensure parameters are properly bound and escaped
- **Test transactions**: Verify begin, commit, and rollback functionality

## 3. Authentication System ✅

- **Test multiple providers**: Verify authentication with different provider types
- **Test fallback mechanism**: Check fallback to secondary providers when primary fails
- **Test token validation**: Ensure proper validation of access tokens
- **Test permissions**: Verify permission checks work properly

### JWTService ✅
- **Test token creation**: Verify JWT tokens are correctly generated
- **Test token validation**: Ensure proper validation logic for tokens
- **Test token expiration**: Check expired tokens are properly detected
- **Test token refresh**: Validate refresh token flows

## 4. Validation System ✅

### Validator ✅
- **Test various validation rules**: Ensure all validation rules work correctly
- **Test custom rules**: Verify custom validation rules can be created and used
- **Test sanitization**: Check input sanitization functions properly
- **Test attribute-based validation**: Verify DTO validation using attributes

## 5. Exception Handling ✅

### ExceptionHandler ✅
- **Test different exception types**: Verify proper handling of various exception classes
- **Test response formatting**: Check that exceptions produce correctly formatted API responses
- **Test logging integration**: Ensure exceptions are properly logged

## 6. Repository Layer ✅

### UserRepository ✅
- **Test user retrieval**: Verify users can be fetched by ID, username, email, etc.
- **Test role associations**: Check that user-role relationships are properly maintained
- **Test password handling**: Ensure password hashing and validation works correctly

### RoleRepository ✅
- **Test RBAC functionality**: Verify role-based access control works as expected
- **Test permission assignment**: Check that permissions can be assigned to roles

### PermissionRepository ✅
- **Test permission retrieval**: Verify permissions can be fetched for specific roles
- **Test permission checks**: Verify methods to check if roles have specific permissions
- **Test permission assignment**: Ensure permissions can be assigned to and revoked from roles

### NotificationRepository ✅
- **Test notification storage**: Verify notifications are properly saved to the database
- **Test notification retrieval**: Ensure notifications can be fetched by various criteria
- **Test preference management**: Verify notification preferences can be saved and retrieved

## 7. Logging System ✅

### LogManager ✅
- **Test log levels**: Verify log level filtering works correctly
- **Test channel-based logging**: Ensure channel separation works
- **Test log output formats**: Verify both text and JSON formatting
- **Test log storage options**: Check file and database logging
- **Test performance tracking**: Verify timer functionality

## 8. File Management

### FileHandler
- **Test file uploads**: Verify file uploads are processed correctly
- **Test file retrieval**: Check file retrieval works by UUID and other attributes
- **Test image processing**: Verify resizing and other image operations

## 9. Extension System ✅

### ExtensionsManager ✅
- **Test extension loading**: Verify extensions are properly loaded
- **Test hook integration**: Check that extension hooks are called at appropriate times
- **Test extension configuration**: Ensure configuration options are properly loaded

## 10. Security Features ✅

### PasswordHasher ✅
- **Test password hashing**: Verify passwords are securely hashed
- **Test password verification**: Ensure password verification works correctly

### Security-related middleware ✅
- **Test CORS protection**: Verify CORS headers are correctly applied
- **Test rate limiting**: Check that rate limiting properly restricts excessive requests

## Best Practices for Implementing These Tests

1. **Start with high-risk areas**: Focus on auth, database, and validation first
2. **Create test fixtures**: Build fixture data for consistent test outcomes
3. **Use test doubles**: Employ mocks and stubs for external dependencies
4. **Test edge cases**: Include tests for error conditions and boundary values 
5. **Organize tests by component**: Mirror the application structure in the test suite
6. **Use data providers**: For testing multiple variations of the same logic
7. **Set up automated testing**: Integrate with CI/CD pipeline
8. **Include integration tests**: Test component interactions in addition to unit tests
9. **Measure code coverage**: Aim for high coverage of critical components
10. **Maintain test documentation**: Document the purpose and scope of each test

## Sample Test Structure

```
/tests
  /Unit
    /API
      APITest.php
      RequestLifecycleTest.php
    /Auth
      AuthenticationManagerTest.php
      JWTServiceTest.php
    /Database
      ConnectionTest.php
      QueryBuilderTest.php
      TransactionTest.php
    /Validation
      ValidatorTest.php
      RulesTest.php
  /Integration
    /API
      RoutingTest.php
    /Database
      RepositoryTest.php
    /Extensions
      ExtensionLoadingTest.php
  /Fixtures
    UserFixtures.php
    RoleFixtures.php
  bootstrap.php
  TestCase.php
```

## First Steps

1. Set up PHPUnit configuration (already added to composer.json)
2. Create basic test architecture
3. Write test for core critical components (Auth, Validation)
4. Set up test database configuration
5. Implement database test helpers and fixtures
6. Gradually expand test coverage across other components
