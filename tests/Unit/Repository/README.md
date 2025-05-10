# Repository Layer Tests

This directory contains unit tests for the repository layer of the Glueful framework. The repository layer is responsible for database interactions and implements the repository pattern to abstract data access logic.

## Overview of Repository Tests

### UserRepositoryTest

Tests the `UserRepository` class which handles user data operations:

- **User retrieval**: Testing methods for fetching users by ID, username, email, etc.
- **User existence checks**: Validating methods that check if usernames/emails already exist
- **User creation**: Testing user account creation functionality
- **Password handling**: Ensuring password hashing and verification works correctly

### RoleRepositoryTest

Tests the `RoleRepository` class which handles role management:

- **Role retrieval**: Testing methods to get all roles and specific roles by ID or name
- **User-role associations**: Testing functions that assign roles to users and check associations
- **Role management**: Testing role creation, updating, and deletion operations
- **RBAC (Role-Based Access Control)**: Verifying role-based access control functionality

### PermissionRepositoryTest

Tests the `PermissionRepository` class which manages permissions:

- **Permission retrieval**: Testing methods to get permissions for specific roles
- **Permission checks**: Validating methods that check if roles have specific permissions
- **Permission assignment**: Testing functions that assign and revoke permissions to roles
- **User permission verification**: Checking if users have specific permissions through their roles

### NotificationRepositoryTest

Tests the `NotificationRepository` class which handles notification storage and retrieval:

- **Notification storage**: Testing methods to save notifications to the database
- **Notification retrieval**: Validating methods that retrieve user-specific notifications
- **Notification status**: Testing marking notifications as read/unread
- **Notification preferences**: Testing retrieval and storage of user notification preferences
- **Template management**: Testing notification template storage and retrieval

## Running the Tests

To run all repository tests:

```bash
./vendor/bin/phpunit tests/Unit/Repository
```

To run a specific repository test class:

```bash
./vendor/bin/phpunit tests/Unit/Repository/UserRepositoryTest.php
```

## Best Practices Used

1. **Mock Dependencies**: All external dependencies are mocked to isolate tests from actual database operations
2. **Test Edge Cases**: Included tests for both successful and failed operations
3. **Comprehensive Coverage**: Tested all main repository methods
4. **Clear Documentation**: Each test is documented with clear comments about what's being tested
5. **Consistent Structure**: All test classes follow the same structure for easier maintenance
