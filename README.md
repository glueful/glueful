# Glueful - Modern API Framework

A modern, secure, and scalable API framework designed for building robust PHP applications.

![Version](https://img.shields.io/badge/version-0.29.0-blue)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-purple)](https://www.php.net/)
![PHP CI](https://github.com/michaeltawiahsowah/glueful/workflows/PHP%20CI/badge.svg)
[![Tests](https://img.shields.io/badge/tests-passing-green)]()

## Core Features

- Modern PHP architecture (PHP 8.2+)
- Role-based access control (RBAC)
- RESTful API endpoints with comprehensive OpenAPI/Swagger documentation
- Modern API documentation UI with dark/light theme support
- CLI tools for database management and development server
- Extensible command system with comprehensive console tools
- Database migrations and schema management
- Rate limiting capabilities with adaptive limiting
- JWT-based authentication with dual-layer session storage
- Comprehensive audit logging for security events
- Performance-optimized database query logging with N+1 detection
- File storage and management with multiple storage drivers
- High-performance Extension System v2.0 (50x faster loading)
- Admin dashboard SPA with real-time configuration
- Comprehensive testing infrastructure

## Requirements

- PHP 8.2 or higher
- MySQL 5.7+ or PostgreSQL 12+
- PDO PHP Extension
- OpenSSL PHP Extension
- JSON PHP Extension
- Mbstring PHP Extension
- Composer 2.0+

## Installation

1. Create a new project:
```bash
composer create-project glueful/glueful my-api
cd my-api
```

2. Configure your environment:
```bash
cp .env.example .env
```

3. Set up your database configuration in `.env`:
```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
```

4. Generate secure keys:
```bash
php glueful generate:key
```

## Database Setup

### MySQL Setup
```bash
# Create database and user
mysql -u root -p
CREATE DATABASE glueful CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'glueful_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON glueful.* TO 'glueful_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Update .env configuration
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=glueful
DB_USER=glueful_user
DB_PASSWORD=strong_password
```

### PostgreSQL Setup
```bash
# Create database and user
sudo -u postgres psql
CREATE DATABASE glueful;
CREATE USER glueful_user WITH PASSWORD 'strong_password';
GRANT ALL PRIVILEGES ON DATABASE glueful TO glueful_user;
\q

# Update .env configuration
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=glueful
DB_USER=glueful_user
DB_PASSWORD=strong_password
```

### SQLite Setup
```bash
# SQLite requires no database server setup
# Update .env configuration
DB_DRIVER=sqlite
DB_DATABASE=database/primary.sqlite

# Create database directory if it doesn't exist
mkdir -p database
```

### Run Migrations
```bash
# After configuring your preferred database
php glueful migrate:run
```

## CLI Commands

Glueful comes with a powerful CLI tool for system management:

### Development Server
```bash
# Start development server
php glueful serve

# Start on custom port
php glueful serve --port=8080

# Auto-open browser
php glueful serve --open
```

### Database Management
```bash
# Run pending migrations
php glueful migrate:run

# Check migration status
php glueful migrate:status

# Create new migration
php glueful migrate:create create_my_table

# Rollback migrations
php glueful migrate:rollback

# Check database status
php glueful db:status

# Reset database (requires confirmation)
php glueful db:reset --force
```

### API Documentation
```bash
# Generate complete OpenAPI/Swagger documentation
php glueful generate:api-definitions

# Generate controller from template
php glueful generate:controller MyController
```

### Extension Management
```bash
# List all extensions
php glueful extensions:info

# List with autoload information  
php glueful extensions:info --show-autoload

# Get extension details
php glueful extensions:info MyExtension

# Show extension namespaces
php glueful extensions:info --namespaces

# Enable/disable extensions
php glueful extensions:enable MyExtension
php glueful extensions:disable MyExtension

# Create new extension
php glueful extensions:create MyExtension

# Install extension
php glueful extensions:install <url-or-archive>

# Advanced management
php glueful extensions:validate MyExtension  # Validate extension
php glueful extensions:benchmark             # Performance testing
php glueful extensions:debug                 # System diagnostics
php glueful extensions:delete MyExtension    # Delete extension
```

### Cache Management
```bash
# Clear all cached data
php glueful cache:clear

# Show cache status
php glueful cache:status

# Get/set/delete cache items
php glueful cache:get <key>
php glueful cache:set <key> <value> [<ttl>]
php glueful cache:delete <key>

# Purge edge cache
php glueful cache:purge

# Set cache TTL
php glueful cache:ttl <key> <seconds>

# Expire cache items
php glueful cache:expire <key> <seconds>
```

### System Management
```bash
# Show all available commands
php glueful list

# Show help for specific command
php glueful help migrate:run

# System health check
php glueful system:check

# Memory monitoring
php glueful system:memory

# Production environment validation
php glueful system:production

# Installation wizard
php glueful install
```

### Security Commands
```bash
# Security configuration check
php glueful security:check

# Generate security report
php glueful security:report

# Scan for vulnerabilities
php glueful security:scan

# Check known vulnerabilities
php glueful security:vulnerabilities

# Emergency lockdown mode
php glueful security:lockdown

# Reset user password
php glueful security:reset-password

# Revoke authentication tokens
php glueful security:revoke-tokens
```

### Queue Management
```bash
# Start queue worker
php glueful queue:work

# Auto-scaling queue management
php glueful queue:autoscale

# Job scheduling system
php glueful queue:scheduler
```

### Configuration Management
```bash
# Validate configuration
php glueful config:validate

# Generate config documentation
php glueful config:generate-docs

# Generate IDE support files
php glueful config:generate-ide-support
```

### Archive System
```bash
# Manage data archiving
php glueful archive:manage
```

### Notification System
```bash
# Process notification retries
php glueful notifications:process-retries
```

### DI Container Management
```bash
# Validate container configuration
php glueful di:container:validate

# Debug container services
php glueful di:container:debug

# Compile container for production
php glueful di:container:compile
```

## API Endpoints

Glueful provides a comprehensive set of RESTful API endpoints:

### Authentication
- `POST /auth/login`: User authentication with JWT tokens
- `POST /auth/logout`: User logout (invalidates tokens)
- `POST /auth/refresh-token`: Refresh JWT access token
- `POST /auth/validate-token`: Validate current token
- `POST /auth/forgot-password`: Initiate password reset
- `POST /auth/reset-password`: Reset password with code
- `POST /auth/verify-email`: Send email verification code
- `POST /auth/verify-otp`: Verify one-time password
- `GET /csrf-token`: Get CSRF token for forms

### Resource Management (Dynamic)
Glueful uses a dynamic resource system for database tables:
- `GET /{resource}`: List resources with pagination and filtering
- `POST /{resource}`: Create new resource
- `PUT /{resource}/{uuid}`: Update specific resource
- `DELETE /{resource}/{uuid}`: Delete specific resource
- `DELETE /{resource}/bulk`: Bulk delete (if enabled)
- `PUT /{resource}/bulk`: Bulk update (if enabled)

### Health & System
- `GET /health`: Overall system health check
- `GET /health/database`: Database connectivity check
- `GET /health/cache`: Cache system health check

### Files & Storage
- `GET /files`: List uploaded files
- `POST /files`: Upload file with metadata
- `GET /files/{id}`: Get file information
- `DELETE /files/{id}`: Delete file

### Extensions & Admin
- `GET /extensions`: List installed extensions
- `POST /extensions/{name}/enable`: Enable extension
- `POST /extensions/{name}/disable`: Disable extension
- Various admin endpoints via Admin extension

## Rate Limiting

Glueful includes built-in rate limiting capabilities:

```php
// Limit by IP: 60 attempts per minute
$limiter = RateLimiter::perIp(
    ip: $_SERVER['REMOTE_ADDR'],
    maxAttempts: 60,
    windowSeconds: 60
);

// Limit by user: 1000 attempts per day
$limiter = RateLimiter::perUser(
    userId: $user->getId(),
    maxAttempts: 1000,
    windowSeconds: 86400
);
```

## Configuration Guide

### Essential Environment Variables

```env
# Application
APP_NAME="Glueful"
APP_ENV=development
BASE_URL=http://localhost:8000
API_BASE_URL=http://localhost:8000/api/
API_VERSION=v1
API_VERSION_FULL=1.0.0
API_DEBUG=true

# Security
ACCESS_TOKEN_LIFETIME=900  # 15 minutes
REFRESH_TOKEN_LIFETIME=604800  # 7 days
JWT_KEY=your-secure-jwt-key-here
TOKEN_SALT=your-secure-salt-here
JWT_ALGORITHM=HS256

# Database
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
DB_MYSQL_ROLE=primary

# Cache
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CACHE_DB=1

# CORS Configuration
CORS_ALLOWED_ORIGINS=http://localhost,http://localhost:3000,http://localhost:5173,http://localhost:8080

# Security Headers
CSP_HEADER=""
HSTS_HEADER="max-age=31536000; includeSubDomains"

# Feature Flags
ENABLE_PERMISSIONS=true
FORCE_ADVANCED_EMAIL=true
API_DEBUG_MODE=true
```

### Authentication Model
Glueful implements a JWT-based authentication system with dual-layer session storage:

- **Public endpoints**: Authentication, health checks, CSRF token generation
- **Protected endpoints**: All resource operations and file management require authentication
- **Admin endpoints**: Extension management and admin functions require admin privileges
- **Dynamic resources**: Database table access through RESTful resource routes
- **Session storage**: Database + cache layer for optimal performance
- **Token management**: Access tokens (15min) and refresh tokens (7 days)

### Security Notes
- All passwords are hashed using bcrypt
- Audit logging enabled for critical database operations
- Uses prepared statements to prevent SQL injection
- Implements role-based access control
- Support for IP-based and user-based rate limiting
- All resource and file operations are protected by authentication guards
- JWT tokens used for stateless authentication

## Documentation

### Interactive API Documentation
- Modern RapiDoc UI available at `/docs/index.html`
- Dark/Light theme support with system preference detection
- Comprehensive OpenAPI schemas for all endpoints
- Interactive "Try it out" functionality
- Authentication testing with JWT tokens
- Server environment selection

### Core Documentation
- Database schema documentation in `/docs/SCHEMA.md`
- Rate limiter documentation in `/docs/RATELIMITER.md`
- Setup guide in `/docs/SETUP.md`
- Validation system in `/docs/VALIDATION.md`
- Console commands reference in `/docs/CONSOLE_COMMANDS.md`
- Caching system guide in `/docs/CACHING_SYSTEM.md`
- Queue system documentation in `/docs/QUEUE_SYSTEM.md`
- Performance optimization in `/docs/PERFORMANCE_OPTIMIZATION.md`
- Memory management guide in `/docs/MEMORY_MANAGEMENT.md`
- Logging system documentation in `/docs/LOGGING_SYSTEM.md`
- Event system guide in `/docs/EVENTS.md`

### Production Guides
- **[Security Hardening Guide](/docs/SECURITY.md)** - Comprehensive security checklist and best practices
- **[Deployment Guide](/docs/DEPLOYMENT.md)** - Docker, cloud, and traditional server deployment strategies
- **[Error Handling Guide](/docs/ERROR_HANDLING.md)** - Server-side and client-side error handling patterns

### Admin Dashboard
The built-in Admin extension provides a comprehensive SPA dashboard:
- Real-time system monitoring and health checks
- Database table management and querying
- Extension management and configuration
- User and permission management
- API metrics and analytics
- Automatic environment configuration generation

### Extension System
- **Extension management** via comprehensive CLI commands
- **Dynamic loading** with PSR-4 autoloading support
- **Dependency validation** and conflict resolution
- **Performance benchmarking** and health monitoring
- **Namespace management** with conflict detection

### Additional Resources
- Feature documentation in `/docs/features/`
- Performance optimization guides in `/docs/`
- Extension development in `/docs/`

## Development Workflow

### Creating a New Feature
```bash
# Create migration for database changes
php glueful migrate:create create_feature_table

# Generate controller
php glueful generate:controller FeatureController

# Run migrations
php glueful migrate:run

# Generate API documentation
php glueful generate:api-definitions

# Start development server
php glueful serve --port=8000 --open
```

### Testing and Validation
```bash
# Validate system configuration
php glueful system:check

# Check security configuration
php glueful security:check

# Validate DI container
php glueful di:container:validate

# Check database status
php glueful db:status
```

## Backup and Restore
```bash
# Create backup
mysqldump -u glueful_user -p glueful > backup_$(date +%Y%m%d).sql

# Restore from backup
mysql -u glueful_user -p glueful < backup_20240101.sql

# Archive old data
php glueful archive:manage
```

## License

[MIT License](LICENSE)

## Support

For issues and feature requests, please use the GitHub issue tracker.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.
