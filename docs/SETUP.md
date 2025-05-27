# Database Setup Guide

## Prerequisites
- MySQL 8.0 or higher
- PHP 8.2.0 or higher (Required)
  ```bash
  # Check PHP version
  php -v
  
  # If needed, update PHP:
  # For MacOS with Homebrew:
  brew update
  brew upgrade php
  
  # For Ubuntu/Debian:
  sudo add-apt-repository ppa:ondrej/php
  sudo apt update
  sudo apt install php8.2
  ```
- Composer
- OpenSSL for encryption keys
- Minimum 4GB RAM recommended

## Installation Steps

### Development Environment
1. Clone the repository
2. Create a secure database user
   ```bash
   mysql -u root -p
   CREATE DATABASE glueful CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'glueful_user'@'localhost' IDENTIFIED BY 'strong_password';
   GRANT ALL PRIVILEGES ON glueful.* TO 'glueful_user'@'localhost';
   FLUSH PRIVILEGES;
   ```
3. Configure environment
   ```bash
   cp .env.example .env
   # Note: Key generation not yet implemented
   ```
4. Update .env with database credentials
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=glueful
   DB_USERNAME=glueful_user
   DB_PASSWORD=strong_password
   ```
5. Run migrations
   ```bash
   php glueful migrate
   ```

### Production Environment
1. Follow security best practices
   - Use strong passwords
   - Limit database user privileges
   - Enable SSL for database connections
   - Configure proper firewall rules

2. Set up production environment
   ```bash
   cp .env.example .env
   ```

3. Run migrations
   ```bash
   php glueful migrate
   ```

## Maintenance

### Database Backups
1. Configure automated backups
   ```bash
   mysqldump -u glueful_user -p glueful > backup_$(date +%Y%m%d).sql
   ```

### Available Commands
Currently implemented commands:
```bash
php glueful help              # Show available commands
php glueful generate:json     # Generate JSON files
php glueful migrate           # Run database migrations
php glueful db:status        # Show database connection status and statistics
php glueful db:reset         # Reset database (requires --force flag)
```

Examples:
```bash
# Check database status
php glueful db:status

# Reset database (warning: destructive operation)
php glueful db:reset --force
```

Note: Always backup your database before running destructive commands.

### Adding New Tables
1. Create migration file
   ```bash
   php glueful make:migration create_table_name
   ```
2. Update schema documentation in SCHEMA.md
3. Test migration locally
   ```bash
   php glueful migrate run --dry-run
   php glueful migrate run
   ```

### Troubleshooting
- Check MySQL logs: `/var/log/mysql/error.log`
- Verify database connections: `php glueful db:status`
- Reset database: `php glueful db:reset --force`

### Security Notes
- Regularly update MySQL to latest version
- Monitor audit logs for suspicious activity
- Use prepared statements for all queries
- Implement rate limiting on database operations