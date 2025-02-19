# Glueful Database

## Overview
Core database implementation for the Glueful framework providing:
- User management system
- Role-based access control (RBAC)
- Audit logging
- Session management
- File attachment support

## Directory Structure
```bash
database/
├── init/          # Fresh installation scripts
│   ├── tables/    # Table creation SQL files
│   ├── seed/      # Initial data population
│   ├── functions/ # Database functions and triggers
│   └── init.sql   # Main initialization script
├── migrations/    # Incremental database changes
├── functions/     # Reusable MySQL functions
└── docs/         # Documentation
```

## Available Commands

### Database Management
```bash
# Run pending migrations
php glueful db:migrate

# Check database status
php glueful db:status

# Reset database (requires confirmation)
php glueful db:reset --force

# Generate JSON schema
php glueful generate:json api-definitions -d mydb -T users
```

### Command Help
```bash
# Show all available commands
php glueful help

# Show help for specific command
php glueful help db:migrate
```

## Database Operations

### Fresh Installation
```bash
# 1. Create database and user
mysql -u root -p
CREATE DATABASE glueful CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'glueful_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON glueful.* TO 'glueful_user'@'localhost';
FLUSH PRIVILEGES;

# 2. Run initialization script
mysql -u glueful_user -p glueful < database/init/init.sql
```

### Backup and Restore
```bash
# Create backup
mysqldump -u glueful_user -p glueful > backup_$(date +%Y%m%d).sql

# Restore from backup
mysql -u glueful_user -p glueful < backup_20240101.sql
```

## Security Notes
- All passwords are hashed using bcrypt
- Audit logging enabled for critical tables
- Uses prepared statements for SQL operations
- Implements role-based access control

For detailed documentation:
- See [Schema Documentation](docs/SCHEMA.md)
- See [Setup Guide](docs/SETUP.md)