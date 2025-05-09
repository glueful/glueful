# Changelog

All notable changes to the Glueful framework will be documented in this file.

## [0.23.0] - 2025-05-09

### Added
- Comprehensive authentication test suite
  - JWTServiceTest for JWT token generation and validation
  - AuthenticationManagerTest for authentication provider management
  - JwtAuthenticationProviderTest for JWT-specific authentication flows
- MockCache implementation for testing cache-dependent code without requiring external cache servers
- Helper script (run-tests.sh) for running tests with custom PHP binary path

### Fixed
- Fixed authentication system to properly align with test expectations
- Improved session management in token-based authentication
- Enhanced admin role verification with proper nested user structure support

## [0.22.0] - 2025-05-09

### Added
- Complete PHPUnit integration with separate test suites
- Structured test directory following industry best practices
- Base TestCase and DatabaseTestCase classes for testing
- Testing utilities and fixture system
- Database testing with in-memory SQLite
- Initial tests for API initialization and validation

v0.22.0 - Test Infrastructure Release (May 9, 2025)
- Added comprehensive unit testing infrastructure
- Integrated PHPUnit with custom test cases
- Created base testing classes for framework components
- Implemented testing directory structure following best practices
- Added database testing support with in-memory SQLite
- Created composer scripts for running different test suites
- Added initial test coverage for core components
- Created detailed testing plan in UNIT_TESTING_PLAN.md
