# Changelog

All notable changes to the Glueful framework will be documented in this file.

## [0.32.3] - 2025-08-10

### Fixed
- **RBAC Bootstrap Errors**
  - Fixed "relation 'permissions' does not exist" error during framework bootstrap when RBAC tables don't exist yet
  - Added table existence checks in RBAC service provider to gracefully handle migration/installation scenarios
  - RBAC extension now skips initialization during setup phase when tables are not yet available

### Performance Improvements
- **RBAC Migration Optimization (87% Database Query Reduction)**
  - Refactored RBAC seeding migration to use batch operations instead of individual queries
  - Roles: 4 individual existence checks → 1 batch `whereIn()` query
  - Permissions: 15 individual existence checks → 1 batch `whereIn()` query  
  - Core permission verification: 5 individual queries → 1 batch `whereIn()` query
  - All inserts now use proper `insertBatch()` operations for maximum efficiency
- **Development Query Monitoring**
  - Fixed query pattern detection to properly identify table names with quoted identifiers
  - Improved N+1 query detection accuracy by using original SQL instead of normalized SQL
  - Enhanced pattern matching to distinguish batch operations from individual queries

### Technical Improvements
- **Query Builder Enhancements**
  - Added `max()` method to QueryBuilder for database-agnostic aggregate queries
  - Improved PostgreSQL compatibility for auto-increment fields using BIGSERIAL
  - Enhanced query pattern extraction for better development debugging

## [0.32.2] - 2025-08-10

### Fixed
- **PostgreSQL Connection Error**
  - Fixed "invalid connection option 'search_path'" error preventing PostgreSQL connections
  - Removed `search_path` from PostgreSQL DSN string where it's not a valid connection parameter
  - Properly set search_path using SQL command after connection is established
  - Applied fix to both Connection.php and ConnectionPool.php for consistency
  - Ensures PostgreSQL schema configuration works correctly across all connection types

## [0.32.1] - 2025-08-09

### Fixed
- **CSRF Token Cache Compatibility**
  - Fixed "Cache key contains invalid characters" error when using file-based cache driver
  - Changed CSRF token cache key prefix from `csrf_token:` to `csrf_token_` for compatibility
  - Ensures CSRF protection works correctly with all cache drivers (file, Redis, Memcached)
  - Resolves issue where colon character was rejected by file and Memcached cache drivers

### Improved
- **Extension Migration Support**
  - Installation process now runs migrations twice to include extension-specific migrations
  - Extensions.json is restored before running extension migrations during installation
  - Ensures RBAC and other extension database tables are properly created
  - Added graceful error handling if extension migrations fail

## [0.32.0] - 2025-08-07

### Fixed
- **Critical Installation Failure**
  - Fixed "Unable to read any of the environment file(s)" error that prevented fresh installations
  - Resolved chicken-and-egg problem where install command required database before .env existed
  - Changed default database to SQLite for zero-configuration installation
  - Installation now works immediately without any database setup required
  - Fixed glueful CLI script to create .env from .env.example before bootstrap
  - Temporarily disables extensions during installation to prevent initialization errors
- **Security Key Generation**
  - Fixed missing APP_KEY configuration that wasn't wired up in `config/app.php`
  - InstallCommand now properly generates all three security keys (APP_KEY, JWT_KEY, TOKEN_SALT)
  - Fixed validation bug where "TOKEN_SALT is too short" warning appeared with valid 32-character salts
  - Security health check now reads directly from .env file to avoid config caching issues
- **Installation Error Handling**
  - Fixed misleading "Installation failed" message for simple user input errors
  - Password mismatch and input cancellation now show as warnings, not failures
  - Added proper validation for empty passwords and minimum length requirements
  - Installation correctly continues even if optional admin user creation fails
- **SQLite Path Resolution**
  - Fixed duplicate database creation in public/storage directory
  - Database paths now resolve correctly from project root in all contexts
  - Added intelligent path resolution - relative paths use project root, absolute paths unchanged

### Improved
- **Installation Experience**
  - Better error messages that distinguish between actual failures and user input issues
  - Clear indication that installation succeeded when only admin creation failed
  - Helpful recovery options provided for partial installations
  - More informative password validation messages
- **Web Setup Wizard**
  - Updated to reflect SQLite as the default zero-configuration option
  - Simplified database step - no configuration needed by default
  - MySQL/PostgreSQL options moved to advanced collapsible section
- **Database Configuration**
  - Added PostgreSQL configuration template to .env.example
  - Improved path handling for Windows compatibility
  - Database pooling disabled by default for SQLite

### Changed
- **Default Database Driver**
  - Changed from MySQL to SQLite to enable zero-configuration installation
  - This is a breaking change but necessary to fix the installation blocker
  - Users can still switch to MySQL/PostgreSQL by updating .env after installation

## [0.31.0] - 2025-08-02

### Added
- **Web Setup Wizard**
  - Browser-based installation interface as an alternative to CLI installation
  - Complete setup package in new `/setup` directory with MVC structure
  - SetupController for handling web-based installation flow
  - Interactive system requirements checker with visual pass/fail indicators
  - Database configuration form with connection testing capability
  - Admin user creation with real-time password validation
  - Professional UI with Glueful branding (shark and boulder color scheme)
  - Mobile-responsive design using CSS Grid and Flexbox
  - Client-side form validation and data persistence with localStorage
  - Integration with existing CLI install command for backend processing
- **Permission System Enhancements**
  - Added `assignRole()` and `revokeRole()` methods to PermissionManagerInterface
  - Added role assignment methods to PermissionProviderInterface
  - Implemented role management facade methods in PermissionManager
  - Enhanced permission system with direct role assignment capabilities
- **CLI Command Improvements**
  - Added `--skip-cache` option to InstallCommand for skipping cache initialization
  - Added `--skip-db` as an alias for `--skip-database` option
  - Enhanced InstallCommand with better environment variable handling for web setup
  - Improved admin user creation flow in quiet mode

### Improved
- **Database Configuration**
  - Changed database engine configuration from `DB_ENGINE` to `DB_DRIVER` for consistency
  - Fixed SQLite database paths to use `/storage/database/` directory
  - Simplified database driver configuration by removing redundant env() calls
- **Web Setup Experience**
  - Added visual feedback for system requirement checks
  - Implemented step-by-step navigation with progress tracking
  - Added form data persistence between setup steps
  - Enhanced error handling with user-friendly messages
- **API Definitions Command**
  - Enhanced error handling and validation in ApiDefinitionsCommand
  - Improved OpenAPI schema generation process

### Fixed
- **Routing Configuration**
  - Added setup-specific routes for CSS, JavaScript, and SVG assets
  - Fixed static file serving with proper MIME types
  - Resolved navigation between setup wizard steps
- **Autoloading**
  - Added PSR-4 autoloading for setup namespace in composer.json
  - Fixed namespace resolution for SetupController

### Technical Details
- Created new setup module structure:
  - `/setup/Controllers/SetupController.php` - Main controller
  - `/setup/index.php` - View template
  - `/setup/setup.css` - Styling with custom properties
  - `/setup/setup.js` - Client-side interactions
- Modified composer.json to include: `"Glueful\\Setup\\": "setup/"`
- Enhanced public routes to handle both `/setup` and `/api/v1/setup` paths
- Integrated web setup with CLI install command using environment variables

## [0.30.0] - 2025-07-31

### Added
- **Comprehensive PHPDoc Documentation Coverage**
  - Added extensive documentation to 127+ methods across core framework components
  - Database Layer: Complete documentation for QueryBuilder, Connection, and DatabaseInterface (22 methods)
  - Authentication System: Documented AuthenticationService and TokenManager with examples (12 methods)
  - Extension System: Complete documentation for extension loading, validation, and management (35+ methods)
  - Repository Classes: Added PHPDoc to all repository methods with usage examples (8 methods)
  - Controllers: Comprehensive API endpoint documentation with parameter details (50+ methods)
- **Formal API Stability Governance**
  - Created comprehensive Deprecation Policy (DEPRECATION_POLICY.md) with 2-major-version lifecycle
  - Established Breaking Change Management Process (BREAKING_CHANGE_PROCESS.md) with 5-phase workflow
  - Defined change classification system (Major/Minor/Micro breaking changes)
  - Added emergency procedures for security-critical changes
- **Multi-Database Setup Documentation**
  - Enhanced README.md with setup instructions for MySQL, PostgreSQL, and SQLite
  - Added database-specific configuration examples and setup commands
  - Improved installation documentation with multiple installation methods

### Improved
- **Security Documentation**
  - Enhanced QueryBuilder methods with critical SQL injection warnings
  - Added comprehensive security notes for raw SQL execution methods
  - Improved database compatibility documentation for driver-specific features
- **CLI Command Consistency**
  - Standardized all CLI commands from "./glueful" to "php glueful" format across documentation
  - Updated BREAKING_CHANGE_PROCESS.md, ROADMAP.md, and README.md for consistency
- **Database Query Optimization**
  - Eliminated duplicate database queries between bootstrap validation and CLI commands
  - Enhanced ConnectionValidator caching mechanism to prevent redundant health checks
  - Improved system startup performance by reducing duplicate connectivity tests

### Fixed
- **Critical Authentication Bug**
  - Fixed syntax error in AuthenticationService.php (line 499) that prevented user data retrieval
  - Corrected variable reference that was blocking authentication functionality
- **Database Interface Cleanup**
  - Removed duplicate interface methods (getConnection/getPDO duplication)
  - Cleaned up deprecated getSchemaManager() method references
  - Streamlined database connection handling for better performance
- **Query Execution Performance**
  - Fixed duplicate query execution in HealthService between startup validation and system checks
  - Reduced redundant database connectivity tests during framework initialization
  - Optimized system health check performance

### Documentation
- **API Stability Documentation**
  - Complete governance framework for managing API changes and deprecations
  - Clear guidelines for breaking change communication and migration support
  - Established timeline and process for major version releases
- **Enhanced Code Documentation**
  - JWT token structure documentation with OIDC compliance details
  - Multi-provider authentication flow documentation
  - Extension system architecture documentation with dependency management
  - Database query builder security best practices
- **Installation and Setup**
  - Multi-database configuration examples (MySQL, PostgreSQL, SQLite)
  - Improved setup documentation with alternative installation methods
  - Enhanced CLI command reference with consistent formatting

## [0.29.0] - 2025-07-30

### Added
- Complete Query Builder redesign with modular architecture
  - Replaced monolithic 2,184-line QueryBuilder with orchestrator pattern
  - New modular components following Single Responsibility Principle
  - Separated query building into focused interfaces and implementations:
    - SelectBuilder, InsertBuilder, UpdateBuilder, DeleteBuilder
    - WhereClause, JoinClause, QueryModifiers components
    - QueryState management for maintaining query context
- Advanced Database Schema Builder system
  - Fluent schema building API with database-agnostic operations
  - TableBuilder with comprehensive column type support
  - ColumnBuilder, ForeignKeyBuilder, AlterTableBuilder
  - Database-specific SQL generators (MySQL, PostgreSQL, SQLite)
  - Schema DTOs for type-safe schema definitions
- Query Execution Layer
  - QueryExecutor with caching, logging, and error handling
  - ParameterBinder for secure parameter binding
  - ResultProcessor for consistent result handling
  - Execution plan analyzer and query profiling
- Transaction Management System
  - TransactionManager with deadlock retry support
  - SavepointManager for nested transaction handling
  - Transaction-level logging and monitoring
  - Automatic rollback on failure
- Query Features and Tools
  - QueryPurpose tracking for business context
  - QueryValidator for input validation
  - SoftDeleteHandler for automatic soft delete support
  - PaginationBuilder for efficient pagination
  - QueryPatternRecognizer for query optimization
- Database Connection Pooling (if implemented)
  - ConnectionPool and PooledConnection classes
  - ConnectionPoolManager for multi-pool support
  - PoolMonitor for connection health tracking
- Audit Logs table migration
  - Comprehensive audit logging table structure
  - Support for GDPR compliance with expires_at field
  - Detailed tracking of entity changes

### Improved
- RBAC query performance optimization
  - Eliminated duplicate database queries in permission and role lookups
  - Added static global caching for roles and permissions across repository instances
  - Implemented batch fetching in `findByUuids` methods to prevent N+1 queries
  - Enhanced cache invalidation in RBACPermissionProvider and RoleService
  - Reduced database round trips by up to 50% for permission-heavy requests
- Repository caching architecture
  - Added request-scoped static caches to prevent duplicate queries
  - Implemented smart caching in RoleRepository and PermissionRepository
  - Cache invalidation properly cascades through all dependent systems
- Query Builder architecture
  - Modular design allows for easier testing and maintenance
  - Better separation of concerns with focused components
  - Improved extensibility for adding new query features
  - Enhanced type safety with interfaces and DTOs

### Fixed
- Duplicate query execution in RBAC system
  - Fixed multiple calls to `getUserRoles` from different services
  - Fixed redundant `findRoleByUuid` queries for the same role
  - Fixed duplicate `findPermissionBySlug` queries within single requests
- Performance bottlenecks in permission checking
  - Optimized SessionCacheManager role loading to prevent redundant lookups
  - Fixed inefficient role hierarchy traversal causing repeated queries

### Removed
- Old monolithic QueryBuilder implementation
- Legacy schema management files:
  - MySQLSchemaManager.php
  - PostgreSQLSchemaManager.php
  - SQLiteSchemaManager.php
  - SchemaManager.php (replaced with modular SchemaBuilder system)

### Technical Debt
- Cleaned up unused import statements and parameters
- Fixed code style issues (trailing whitespace)
- Improved code documentation for caching mechanisms
- Refactored database layer to follow SOLID principles

## [0.28.0] - 2025-07-23

### Added
- Extension System v2.0 architecture
  - Modern PSR-4 autoloading with manifest.json configuration
  - BaseExtension architecture for improved extensibility
  - Enhanced extension metadata and dependency management
- Single Page Application (SPA) support for extensions
  - Dynamic SPA configuration and routing
  - Environment-aware asset loading for production deployment
  - Admin panel with modern Vue.js interface
- Comprehensive OpenAPI documentation system
  - Modern documentation UI with interactive schemas
  - Automated API schema generation and validation
  - Enhanced API endpoint documentation
- Dynamic configuration management
  - Real-time env.json generation for SPA extensions
  - Environment-specific configuration handling
  - Centralized configuration validation
- Enhanced logging infrastructure
  - PSR-3 compliant logging with event system integration
  - Request context and performance monitoring
  - Configurable log levels and output formatting
- Unified event dispatching system
  - Complete event-driven architecture implementation
  - Symfony EventDispatcher integration
  - Comprehensive event listener management
- Multi-worker queue system
  - Symfony Process-based queue implementation
  - Enhanced background job processing
  - Improved queue reliability and monitoring
- Database administration improvements
  - Enhanced predefined queries with correct table schemas
  - Environment-aware database query interface
  - Improved query execution and error handling

### Improved
- Framework core modernization
  - Complete migration to Symfony components (DependencyInjection, HttpClient, Serializer, Validator, Config, Console, Process, Lock, OptionsResolver)
  - Enhanced dependency injection container management
  - Improved service provider architecture
- Admin interface production deployment
  - Fixed asset loading paths for production environments
  - Environment-aware routing and navigation
  - Improved logout and authentication flows
- Database query rate limiting
  - Relaxed overly restrictive rate limits for better usability
  - Improved risk assessment thresholds
  - Enhanced error messaging and user feedback
- API configuration management
  - Consolidated API version configuration
  - Removed redundant environment variables
  - Simplified configuration maintenance
- Extension loading and management
  - Improved extension detection and synchronization
  - Enhanced extension configuration access
  - Better error handling for extension operations
- Authentication and security
  - Sanitized email addresses in cache keys for PSR-16 compliance
  - Enhanced token storage and session management
  - Improved authentication provider registration

### Fixed
- Admin panel production deployment issues
  - Fixed asset path resolution for SPA routing
  - Corrected environment configuration loading
  - Resolved logout redirect paths
- Database controller functionality
  - Updated predefined queries to use correct table names (auth_sessions instead of sessions)
  - Fixed query execution permissions and validation
  - Improved error handling for database operations
- Extension system stability
  - Fixed extension installation and detection issues
  - Resolved extension configuration access problems
  - Improved extension metadata handling
- API documentation generation
  - Fixed OpenAPI schema parsing and validation
  - Removed redundant response fields
  - Improved documentation structure and organization
- Core framework issues
  - Fixed DI container registration for controllers and services
  - Resolved PSR-16 cache key validation errors
  - Improved file serving and document root handling

### Documentation
- Enhanced API documentation with OpenAPI schemas
- Comprehensive extension development guides
- Updated deployment and configuration documentation
- Improved troubleshooting and debugging guides

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
- API versioning middleware with multiple strategy support
  - Version validation and routing
  - Backward compatibility management
  - Header and URL-based versioning
- Comprehensive health check system
  - HealthController for system health monitoring
  - HealthService with database and cache connectivity checks
  - PHP extension and configuration validation
  - Dedicated health check API routes
- Installation and setup wizard (InstallCommand)
  - Environment validation and setup
  - Database connection testing
  - Encryption key generation
  - Admin user creation workflow
- CORS handler with configurable options
  - Cross-origin request management
  - Preflight request handling
  - Origin validation with factory methods
  - Environment-based configuration
- Cache services enhancement
  - CacheInvalidationService for targeted cache clearing
  - CacheTaggingService for organized cache management
  - CacheWarmupService for proactive cache population
- Database monitoring and validation tools
  - ConnectionValidator for connection health checks
  - DevelopmentQueryMonitor for query analysis
  - DatabaseException class for better error handling
- Security enhancements
  - Enhanced SecurityManager with request validation
  - Rate limiting with detailed request analysis
  - Size limits and user agent validation
  - RateLimitExceededException and SecurityException classes
- Command-line tools
  - ServeCommand for local development server
  - KeyGenerateCommand for secure key generation
  - SystemCheckCommand for installation validation
- Services configuration consolidation
  - Unified mail, storage, and extensions configuration
  - Centralized configuration management

### Improved
- Overall framework performance at scale
- Database query execution speed
- Memory efficiency for large workloads
- Cache hit rates and distribution
- QueryBuilder with enhanced operator support
  - Support for <, >, <=, >=, !=, LIKE, NOT LIKE operators
  - Backward compatibility maintained
  - Enhanced SQL condition handling
- Scheduled jobs system reliability
  - Fixed missing cron handler classes
  - Corrected SessionCleaner SQL syntax
  - All 5 scheduled jobs now functional
- Exception handling and response structure
  - Standardized validation error messages
  - Consistent response formatting
  - Enhanced error feedback
- Database connection management
  - Shared database connections across controllers
  - Improved query builder performance
- Router functionality
  - Fixed path prefix issues for API routing
  - Better route handling

### Fixed
- Scheduled jobs execution issues
  - Added missing LogCleaner, DatabaseBackup, and CacheMaintenance handlers
  - Fixed SessionCleaner SQL syntax errors
  - Resolved notification retry processing
- Security headers format in environment files
- Database query builder operator syntax
- Router path prefix handling for APIs
- Exception message formatting consistency

### Documentation
- Consolidated performance optimization documentation
- Merged query and database optimization guides into PERFORMANCE_OPTIMIZATION.md
- Consolidated memory management documentation into MEMORY_MANAGEMENT.md
- Added comprehensive deployment guide (DEPLOYMENT.md)
- Created error handling guide (ERROR_HANDLING.md)
- Enhanced security documentation (SECURITY.md)
- Removed 18 fragmented documentation files

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
