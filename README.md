# Glueful - Modern API Framework

A modern, secure, and scalable API framework designed for building robust PHP applications.

## Core Features

- Modern PHP architecture (PHP 8.2+)
- Role-based access control (RBAC)
- CLI tools for database management
- JSON-based API definitions
- Extensible command system
- Database migrations
- API documentation generation

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

# Run initialization script
mysql -u glueful_user -p glueful < database/init/init.sql
```

## CLI Commands

Glueful comes with a powerful CLI tool for database management:

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

### Help System
```bash
# Show all available commands
php glueful help

# Show help for specific command
php glueful help db:migrate
```

## Basic Usage

### Define Database Table
```json
{
    "table": {
        "name": "users",
        "fields": [
            {"name": "id", "type": "int", "nullable": false},
            {"name": "name", "type": "varchar(255)", "nullable": false},
            {"name": "email", "type": "varchar(255)", "nullable": false}
        ]
    },
    "access": {
        "mode": "rw"
    }
}
```

### Create Migration
Place in `database/migrations/YYYYMMDDHHMMSS_create_users_table.sql`:
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

# Database
DB_DRIVER=mysql
DB_HOST=localhost
DB_DATABASE=your_database_name

# Feature Flags
ENABLE_PERMISSIONS=true
API_DEBUG_MODE=true
```

### Security Notes
- All passwords are hashed using bcrypt
- Audit logging enabled for critical tables
- Uses prepared statements for SQL operations
- Implements role-based access control

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
