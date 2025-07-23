# Admin Extension for Glueful

## Overview

The Admin extension provides a comprehensive administrative dashboard and management interface for the Glueful API framework. It offers both a modern web-based UI and extensive REST API endpoints for managing all aspects of your Glueful application, from database operations to system monitoring.

## Features

- ✅ **Web-Based Dashboard** - Modern, responsive administrative interface
- ✅ **Database Management** - Complete database operations and schema management
- ✅ **Migration Control** - View, run, and manage database migrations
- ✅ **Configuration Management** - CRUD operations for application configuration
- ✅ **Job Scheduling** - Manage scheduled jobs and cron tasks
- ✅ **System Monitoring** - Health checks, API metrics, and performance monitoring
- ✅ **Extension Management** - Manage other Glueful extensions
- ✅ **Cache Operations** - Clear and manage application cache
- ✅ **Comprehensive API** - 30+ REST endpoints for programmatic access
- ✅ **Security-First** - Authentication and authorization on all admin operations

## Requirements

- PHP 8.2 or higher
- Glueful Framework 0.27.0 or higher
- Admin user privileges for most operations

## Installation

### Automatic Installation (Recommended)

**Option 1: Using the CLI**

```bash
# Enable the extension if it's already present
php glueful extensions enable Admin

# Or install from external source
php glueful extensions install <source-url-or-file> Admin
```

**Option 2: Manual Installation**

1. Copy the Admin extension to your `extensions/` directory
2. Add to `extensions/extensions.json`:
```json
{
  "extensions": {
    "Admin": {
      "version": "0.18.0",
      "enabled": true,
      "installPath": "extensions/Admin",
      "type": "optional",
      "description": "Administrative dashboard and management interface",
      "author": "glueful-team"
    }
  }
}
```

3. Enable the extension:
```bash
php glueful extensions enable Admin
```

### Verify Installation

Check that the extension is properly enabled:

```bash
php glueful extensions list
php glueful extensions info Admin
```

## Usage

### Accessing the Admin Dashboard

Once enabled, access the admin dashboard at:
```
http://your-domain.com/admin/
```

The dashboard provides:
- System overview with key metrics
- Quick access to major administrative functions
- Responsive design for desktop and mobile access

### Database Management

The admin interface provides comprehensive database management capabilities:

#### Table Operations
- **Create/Drop Tables**: Full table lifecycle management
- **Schema Modification**: Add, modify, or drop columns
- **Index Management**: Create and manage database indexes
- **Foreign Key Constraints**: Set up and manage relationships
- **Data Import/Export**: Bulk data operations

#### Column Operations
- **Add Columns**: Dynamically add new columns to existing tables
- **Modify Columns**: Change column types, constraints, and properties
- **Drop Columns**: Remove columns safely with dependency checking

#### Advanced Features
- **Schema History**: Track and rollback schema changes
- **Data Validation**: Validate data integrity during operations
- **Backup Integration**: Create backups before destructive operations

### Migration Management

Control database migrations through the admin interface:

```php
// View migration status
GET /admin/migrations

// Run pending migrations
POST /admin/migrations/run

// Rollback migrations
POST /admin/migrations/rollback
```

### Configuration Management

Manage application configuration files:

```php
// List all configuration files
GET /admin/configs

// Read specific config
GET /admin/configs/{filename}

// Update configuration
PUT /admin/configs/{filename}

// Create new config file
POST /admin/configs
```

### Job Management

Monitor and manage scheduled jobs:

```php
// List all jobs
GET /admin/jobs

// View job details
GET /admin/jobs/{id}

// Schedule new job
POST /admin/jobs

// Cancel/modify job
PUT /admin/jobs/{id}
```

### System Monitoring

Access comprehensive system metrics:

```php
// System health check
GET /admin/system/health

// API metrics and statistics
GET /admin/system/metrics

// Performance monitoring
GET /admin/system/performance
```

## API Endpoints

The Admin extension provides a comprehensive REST API:

### Database Management
- `GET /admin/db/tables` - List all database tables
- `POST /admin/db/tables` - Create new table
- `DELETE /admin/db/tables/{table}` - Drop table
- `GET /admin/db/tables/{table}/columns` - List table columns
- `POST /admin/db/tables/{table}/columns` - Add column
- `PUT /admin/db/tables/{table}/columns/{column}` - Modify column
- `DELETE /admin/db/tables/{table}/columns/{column}` - Drop column
- `GET /admin/db/tables/{table}/indexes` - List table indexes
- `POST /admin/db/tables/{table}/indexes` - Create index
- `DELETE /admin/db/tables/{table}/indexes/{index}` - Drop index
- `GET /admin/db/tables/{table}/foreign-keys` - List foreign keys
- `POST /admin/db/tables/{table}/foreign-keys` - Create foreign key
- `DELETE /admin/db/tables/{table}/foreign-keys/{key}` - Drop foreign key
- `GET /admin/db/tables/{table}/data` - Export table data
- `POST /admin/db/tables/{table}/data` - Import table data
- `GET /admin/db/schema` - Get complete database schema
- `GET /admin/db/schema/history` - Schema change history
- `POST /admin/db/schema/rollback` - Rollback schema changes

### Migration Management
- `GET /admin/migrations` - List migrations and status
- `POST /admin/migrations/run` - Run pending migrations
- `POST /admin/migrations/rollback` - Rollback migrations

### Configuration Management
- `GET /admin/configs` - List configuration files
- `GET /admin/configs/{filename}` - Read configuration file
- `PUT /admin/configs/{filename}` - Update configuration
- `POST /admin/configs` - Create new configuration file
- `DELETE /admin/configs/{filename}` - Delete configuration file

### Job Management
- `GET /admin/jobs` - List all scheduled jobs
- `GET /admin/jobs/{id}` - Get job details
- `POST /admin/jobs` - Create new job
- `PUT /admin/jobs/{id}` - Update job
- `DELETE /admin/jobs/{id}` - Delete job

### System Monitoring
- `GET /admin/system/health` - System health status
- `GET /admin/system/metrics` - API and system metrics
- `GET /admin/system/performance` - Performance statistics
- `POST /admin/system/cache/clear` - Clear application cache

### Dashboard
- `GET /admin/dashboard` - Comprehensive dashboard data

### Extension Management
- `GET /admin/extensions` - List extensions (delegates to ExtensionsManager)
- Additional extension management endpoints

## Security

The Admin extension implements comprehensive security measures:

### Authentication & Authorization
- **Authentication Required**: All admin routes require valid authentication (`requiresAuth: true`)
- **Admin Privileges**: Sensitive operations require admin privileges (`requiresAdminAuth: true`)
- **Role-Based Access**: Integration with RBAC extension when available

### Security Best Practices
- **Input Validation**: All inputs are validated and sanitized
- **SQL Injection Protection**: Parameterized queries throughout
- **Error Handling**: Secure error messages that don't leak sensitive information
- **Audit Logging**: Administrative actions are logged for accountability

### Route Protection
```php
// Example of protected admin routes
Router::group('/admin', function() {
    Router::get('/dashboard', [AdminController::class, 'dashboard']);
    Router::delete('/db/tables/{table}', [AdminController::class, 'dropTable']);
}, requiresAuth: true, requiresAdminAuth: true);
```

## Configuration

The Admin extension uses the following configuration structure:

```php
// Extension configuration (auto-loaded)
return [
    'dashboard' => [
        'refresh_interval' => 30, // Dashboard refresh interval in seconds
        'max_recent_items' => 10  // Number of recent items to show
    ],
    'database' => [
        'backup_before_schema_changes' => true,
        'max_export_rows' => 10000
    ],
    'security' => [
        'log_admin_actions' => true,
        'require_confirmation' => ['drop_table', 'delete_config']
    ]
];
```

### Environment Variables

No specific environment variables are required, but the extension respects:
- Application debug settings
- Database configuration
- Cache configuration
- Authentication settings

## Integration

### RBAC Integration
When the RBAC extension is available, the Admin extension integrates for enhanced permission management:

```php
// Conditional RBAC integration
if (class_exists('\Glueful\Extensions\RBAC\Services\RoleService')) {
    // Enhanced permission checking and role management
}
```

### Extension System Integration
The Admin extension provides management capabilities for other extensions:
- View installed extensions
- Enable/disable extensions
- View extension information and health status

## Performance Considerations

- **Lazy Loading**: Dependencies are loaded only when needed
- **Efficient Queries**: Optimized database queries for large datasets
- **Caching**: Appropriate caching for frequently accessed data
- **Pagination**: Large result sets are paginated for performance

## Web Interface

The Admin extension includes a modern, responsive web interface featuring:

### Dashboard Design
- **Clean Layout**: Professional gradient design with feature cards
- **Responsive Design**: Mobile-friendly interface
- **Quick Actions**: Direct access to most-used administrative functions
- **System Overview**: Key metrics and status indicators

### Navigation
- **Feature Cards**: Visual access to major administrative areas
- **Breadcrumbs**: Clear navigation hierarchy
- **Search**: Quick search across administrative functions

## Troubleshooting

### Common Issues

1. **Access Denied**: Ensure user has admin privileges
2. **Dashboard Not Loading**: Check that extension is enabled and routes are registered
3. **Database Operations Failing**: Verify database permissions and connectivity
4. **Missing Features**: Ensure dependencies (like RBAC) are installed if needed

### Debug Mode

Enable detailed logging by setting:
```env
APP_DEBUG=true
API_DEBUG_MODE=true
```

### Health Checks

Use the system health endpoint to diagnose issues:
```bash
curl -H "Authorization: Bearer your-token" \
     http://your-domain.com/admin/system/health
```

## License

This extension is licensed under the MIT License.

## Support

For issues, feature requests, or questions about the Admin extension, please create an issue in the repository or consult the Glueful documentation.