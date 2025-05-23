# Changelog

All notable changes to the Glueful framework will be documented in this file.

## [0.27.0] - 2025-05-18

### Added
- Edge caching architecture
  - CDN integration with pluggable adapter system
  - Edge caching configuration manager
  - Cache invalidation patterns for dynamic content
  - Multi-CDN provider support
- Query optimization for complex database operations
  - Database-specific query optimizations
  - Query transformation for better performance
  - Performance improvement estimations
  - Integration with query builder
- Query result caching system
  - Intelligent query result caching
  - Automatic cache invalidation
  - Support for complex query caching
  - Attribute-based cache configuration
- Memory usage optimization in core components
  - Memory monitoring and alerting
  - Efficient memory management tools
  - Memory-efficient iterators
  - Streaming iterators for large datasets
- Distributed cache support
  - Multiple cache node management
  - Configurable replication strategies
  - Health monitoring for cache nodes
  - Automatic failover mechanisms
- Query profiling tools
  - Detailed query performance analysis
  - Execution plan visualization
  - Query pattern recognition
  - Performance bottleneck identification

### Improved
- Overall framework performance at scale
- Database query execution speed
- Memory efficiency for large workloads
- Cache hit rates and distribution

## [0.26.0] - 2025-05-17

### Added
- Complete OAuth 2.0 server implementation
  - Full support for all standard grant types
  - Token management with PKCE support
  - Client registration and management
  - Revocation and introspection endpoints
- SAML and LDAP authentication providers
  - SAML 2.0 integration with major identity providers
  - LDAP/Active Directory authentication support
  - Multiple provider configuration
  - User provisioning and synchronization
- Comprehensive security scanning tools
  - Static code analysis for vulnerabilities
  - Dependency scanning for known CVEs
  - API endpoint security testing
  - Security dashboard and reporting
- Enterprise audit logging system
  - Tamper-evident logging with cryptographic protection
  - Standardized event schema for compliance
  - Multiple storage backends
  - Configurable retention policies
- Compliance toolkits for regulatory requirements
  - GDPR subject rights management
  - CCPA consumer rights controls
  - HIPAA PHI access management
  - Data classification system
- Adaptive rate limiting
  - Behavior-based limiting with anomaly detection
  - Machine learning integration
  - Progressive rate limiting based on behavior patterns
  - Distributed rate limiting across clusters

### Improved
- Enhanced security posture across the entire framework
- Standardized approach to compliance requirements
- Better protection against common attack vectors
- More comprehensive security event tracking

### Deprecated
- Basic rate limiter implementation (will be removed in v1.0.0)

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
