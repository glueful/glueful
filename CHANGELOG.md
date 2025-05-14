# Changelog

All notable changes to the Glueful framework will be documented in this file.

## [0.25.0] - 2025-05-14

### Added
- Complete extension dependency resolution with conflict detection
  - Dependency graph visualization for clearer relationship mapping
  - Version constraint resolution for compatibility checks
  - Conflict resolution suggestions with actionable recommendations
- Extension marketplace in admin panel
  - Extension search and filtering functionality
  - Installation and update UI with progress tracking
  - Extension ratings and reviews integration with GitHub stars
  - Author and publisher profiles via GitHub and social media links
- Standardized extension configuration UI components
  - Common UI component library for consistent interface
  - Schema-based configuration forms generation
  - Configuration validation rules enforcement
- Scaffolding CLI tool for new extensions
  - Interactive extension creation wizard
  - Templates for different extension types (authentication, payment, admin widgets)
  - Automated extension validation
- Automated extension validation tools
  - Code quality checks for extensions
  - Security scanning for potential vulnerabilities
  - Performance impact assessment for extensions

### Improved
- Enhanced extension management capabilities
- Streamlined extension development workflow
- Better error handling and user feedback in extension installation process
- Improved extensibility of the framework core

## [0.24.0] - 2025-05-12

### Added
- Comprehensive testing for FileHandler operations, including upload and validation
- Complete test suites for CORS and Rate Limiter middleware components
- Extensive Router tests for route registration, grouping, and middleware execution
- Enhanced extension system testing with improved fixtures and configuration
- Complete LogManager test coverage including sanitization and enrichment
- Repository classes tests for data integrity validation
- Comprehensive exception handling tests and response formatting
- Custom validation rules implementation and testing
- Improved CI workflow with MySQL service integration

### Improved
- Refactored database tests to use MockSQLiteConnection for better isolation
- Enhanced LogSanitizationTest with real instances of MockLogManager and MockLogSanitizer
- Enhanced unit testing documentation and coverage reporting
- Improved code structure for better readability and maintainability

### Fixed
- Fixed ApiDefinitionGenerator to prevent multiple instantiations causing it to run twice
- Resolved various test failures in authentication modules

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
