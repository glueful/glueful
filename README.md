# Glueful - Modern API Framework

A modern, secure, and scalable API framework designed for building robust PHP applications.

![Version](https://img.shields.io/badge/version-0.22.0-blue)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-purple)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-passing-green)]()

## Core Features

- Modern PHP architecture (PHP 8.2+)
- Role-based access control (RBAC)
- RESTful API endpoints with OpenAPI/Swagger documentation
- CLI tools for database management
- Extensible command system
- Database migrations and schema management
- Rate limiting capabilities
- JWT-based authentication
- Audit logging for security events
- File storage and management
- Modular extension system
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

## Database Setup

### Fresh Installation
```bash
# Create database and user
mysql -u root -p
CREATE DATABASE glueful CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'glueful_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON glueful.* TO 'glueful_user'@'localhost';
FLUSH PRIVILEGES;

# Run initialization and migrations
php glueful db:migrate
```

## CLI Commands

Glueful comes with a powerful CLI tool for system management:

### Database Management
```bash
# Run pending migrations
php glueful db:migrate

# Check database status
php glueful db:status

# Reset database (requires confirmation)
php glueful db:reset --force
```

### API Documentation
```bash
# Generate JSON definitions
php glueful generate:json api-definitions -d mydb -T users

# Generate API documentation
php glueful generate:json doc
```

### Extension Management
```bash
# List all extensions
php glueful extensions list

# Get extension details
php glueful extensions info MyExtension

# Enable/disable extensions
php glueful extensions enable MyExtension
php glueful extensions disable MyExtension

# Create new extension
php glueful extensions create MyExtension
```

### Cache Management
```bash
# Clear all cached data
php glueful cache clear

# Show cache status
php glueful cache status

# Get/set/delete cache items
php glueful cache get <key>
php glueful cache set <key> <value> [<ttl>]
php glueful cache delete <key>
```

### Help System
```bash
# Show all available commands
php glueful help

# Show help for specific command
php glueful help db:migrate
```

## API Endpoints

Glueful provides a comprehensive set of RESTful API endpoints:

### Authentication
- `POST /auth/login`: User authentication
- `POST /auth/reset-password`: Password reset
- `GET /auth/sessions`: Get active sessions

### User Management
- `GET /users`: List all users
- `GET /users/{id}`: Get specific user
- `POST /users`: Create user
- `PUT /users/{id}`: Update user
- `DELETE /users/{id}`: Delete user

### Roles & Permissions
- `GET /roles`: List all roles
- `GET /roles/{id}`: Get specific role
- `POST /roles`: Create role
- `PUT /roles/{id}`: Update role
- `DELETE /roles/{id}`: Delete role
- `GET /role_permissions`: List all permissions
- `POST /role_permissions`: Create permission
- `PUT /role_permissions/{id}`: Update permission
- `DELETE /role_permissions/{id}`: Delete permission

### User Profiles
- `GET /profiles`: List all profiles
- `GET /profiles/{id}`: Get specific profile
- `POST /profiles`: Create profile
- `PUT /profiles/{id}`: Update profile
- `DELETE /profiles/{id}`: Delete profile

### File Management
- `GET /blobs`: List all files
- `GET /blobs/{id}`: Get specific file
- `POST /blobs`: Upload file
- `PUT /blobs/{id}`: Update file metadata
- `DELETE /blobs/{id}`: Delete file

### System Administration
- `GET /migrations`: List database migrations
- `GET /app_logs`: View application logs
- `GET /scheduled_jobs`: List scheduled jobs

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
APP_NAME=Glueful
APP_ENV=development
APP_DEBUG=true
API_VERSION=1.0.0
API_DOCS_ENABLED=true

# Security
ACCESS_TOKEN_LIFETIME=900
REFRESH_TOKEN_LIFETIME=604800
JWT_KEY=your-secure-jwt-key-here
AUTH_GUARD_ENABLED=true

# Database
DB_DRIVER=mysql
DB_HOST=localhost
DB_DATABASE=your_database_name

# Feature Flags
ENABLE_PERMISSIONS=true
API_DEBUG_MODE=true
```

### Authentication Model
Glueful implements a comprehensive authentication system:

- **Public endpoints**: Authentication endpoints (login, token refresh, etc.)
- **Protected endpoints**: All resource and file operations require authentication
- **Admin endpoints**: Administrative functions require admin authentication
- **Authentication guards**: Configurable per route group using `requiresAuth` and `requiresAdminAuth` parameters

### Security Notes
- All passwords are hashed using bcrypt
- Audit logging enabled for critical database operations
- Uses prepared statements to prevent SQL injection
- Implements role-based access control
- Support for IP-based and user-based rate limiting
- All resource and file operations are protected by authentication guards
- JWT tokens used for stateless authentication

## Documentation

- Swagger UI available at `/docs/index.html`
- Database schema documentation in `/docs/SCHEMA.md`
- Rate limiter documentation in `/docs/RATELIMITER.md`
- Setup guide in `/docs/SETUP.md`
- Middleware documentation in `/docs/MIDDLEWARE.md`

## Backup and Restore
```bash
# Create backup
mysqldump -u glueful_user -p glueful > backup_$(date +%Y%m%d).sql

# Restore from backup
mysql -u glueful_user -p glueful < backup_20240101.sql
```

## License

[MIT License](LICENSE)

## Support

For issues and feature requests, please use the GitHub issue tracker.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.
