# Glueful Extension System Documentation

## Overview

The Glueful Extension System provides a high-performance, modular architecture for extending the framework's functionality. With pre-computed autoload mappings and JSON-based configuration, extensions load in under 1ms with minimal memory overhead.

## Table of Contents

- [Quick Start](#quick-start)
- [System Architecture](#system-architecture)
- [Creating Extensions](#creating-extensions)
- [Extension Templates](#extension-templates)
- [Dependency Management](#dependency-management)
- [Command Reference](#command-reference)
- [Built-in Extensions](#built-in-extensions)
- [Social Login Providers](#social-login-providers)
- [Queue Extensions](#queue-extensions)
- [RBAC Extensions](#rbac-extensions)
- [Template System](#template-system)
- [Performance](#performance)
- [Troubleshooting](#troubleshooting)

## Quick Start

### 1. Check System Status

```bash
# List all extensions
php glueful extensions:info

# Validate extension configuration
php glueful extensions:validate

# Run performance benchmark
php glueful extensions:benchmark
```

### 2. Create Your First Extension

```bash
# Create new extension
php glueful extensions:create MyExtension

# Enable it
php glueful extensions:enable MyExtension

# Verify it's loaded
php glueful extensions:info MyExtension
```

### 3. Key Commands

```bash
php glueful extensions:info [name]      # List extensions or show details
php glueful extensions:create <name>    # Create new extension
php glueful extensions:enable <name>    # Enable extension
php glueful extensions:disable <name>   # Disable extension
php glueful extensions:validate <name>  # Validate extension
php glueful extensions:install <source> # Install extension from source
php glueful extensions:delete <name>    # Delete extension completely
php glueful extensions:benchmark        # Performance benchmarks
php glueful extensions:debug            # Debug information
```

## System Architecture

### Extension Structure

```
extensions/
└── MyExtension/
    ├── MyExtension.php        # Main extension class (matches directory name)
    ├── manifest.json          # Extension metadata
    ├── composer.json          # Dependencies
    ├── src/
    │   ├── Controllers/       # Extension controllers
    │   ├── Services/          # Business logic services
    │   ├── Models/            # Data models
    │   ├── Repositories/      # Data repositories
    │   ├── Providers/         # Service providers (for auth extensions)
    │   ├── config.php         # Configuration
    │   └── routes.php         # Route definitions
    ├── migrations/            # Database migrations
    ├── assets/
    │   └── icon.png          # Extension icon
    ├── CHANGELOG.md          # Version history
    └── README.md             # Documentation
```

### Configuration Schema (manifest.json)

```json
{
    "name": "ExtensionName",
    "version": "1.0.0",
    "type": "optional",
    "description": "Extension description",
    "author": "Author Name",
    "license": "MIT",
    "main_class": "Glueful\\Extensions\\ExtensionName",
    "autoload": {
        "psr-4": {
            "Glueful\\Extensions\\ExtensionName\\": "src/"
        }
    },
    "dependencies": {
        "glueful": ">=0.29.0",
        "php": ">=8.2.0",
        "extensions": ["RequiredExtension"]
    },
    "provides": {
        "routes": ["src/routes.php"],
        "migrations": ["migrations/"],
        "commands": ["src/Commands/"]
    }
}
```

## Creating Extensions

### Basic Extension

```bash
php glueful extensions:create MyExtension
```

This creates:
- Basic extension structure
- Main extension class
- Configuration file
- PSR-4 autoloading setup

### Extension Class Template

```php
namespace Glueful\Extensions\MyExtension;

use Glueful\Core\Extension\BaseExtension;
use Glueful\Core\Container\Container;

class Extension extends BaseExtension
{
    public function boot(Container $container): void
    {
        // Register services
        $this->registerServices($container);
        
        // Register routes
        $this->registerRoutes();
        
        // Register event listeners
        $this->registerEventListeners();
        
        // Register middleware
        $this->registerMiddleware();
    }
    
    protected function registerServices(Container $container): void
    {
        $container->singleton(MyService::class, function($container) {
            return new MyService(
                $container->get(DatabaseInterface::class)
            );
        });
    }
}
```

## Extension Templates

### Available Templates

1. **Basic** - Simple extension structure with minimal components
2. **Advanced** - Full-featured extension with services, middleware, and migrations

### Creating from Template

```bash
# Create basic extension
php glueful extensions:create --template=basic MyExtension

# Create advanced extension with full features
php glueful extensions:create --template=advanced PaymentAPI

# Create extension interactively (prompts for template)
php glueful extensions:create MyExtension
```

## Dependency Management

### Declaring Dependencies

Extensions can declare dependencies on:
- PHP version
- Other extensions
- Composer packages

```json
{
    "dependencies": {
        "php": "^8.2",
        "extensions": [
            "CoreAuth",
            "DatabaseExtension"
        ],
        "packages": {
            "guzzlehttp/guzzle": "^7.0",
            "league/oauth2-client": "^2.0"
        }
    }
}
```

### Dependency Resolution

The system automatically:
- Validates dependencies before enabling
- Orders extension loading based on dependencies
- Prevents circular dependencies
- Handles version constraints

### Checking Dependencies

```bash
# Validate all dependencies
php glueful extensions:validate MyExtension

# View dependency tree
php glueful extensions:debug
```

## Command Reference

### Information Commands

```bash
# List all extensions
php glueful extensions:info

# Show specific extension details
php glueful extensions:info ExtensionName

# Show with namespace mappings
php glueful extensions:info --namespaces

# Filter by status
php glueful extensions:info --status=enabled

# Output as JSON
php glueful extensions:info --format=json
```

### Management Commands

```bash
# Enable/disable extensions
php glueful extensions:enable ExtensionName
php glueful extensions:disable ExtensionName

# Create new extension
php glueful extensions:create MyExtension
php glueful extensions:create --template=advanced SocialAuth

# Install from various sources
php glueful extensions:install https://example.com/extension.zip
php glueful extensions:install /path/to/extension.zip
php glueful extensions:install git@github.com:user/extension.git

# Delete extension with options
php glueful extensions:delete ExtensionName --force
php glueful extensions:delete ExtensionName --backup
php glueful extensions:delete ExtensionName --dry-run
```

### Development Commands

```bash
# Validate extension structure and dependencies
php glueful extensions:validate ExtensionName
php glueful extensions:validate ExtensionName --autofix

# Debug system state and issues
php glueful extensions:debug
php glueful extensions:debug --verbose

# Performance benchmarking
php glueful extensions:benchmark
php glueful extensions:benchmark --iterations=100
php glueful extensions:benchmark --memory-profile
```

## Built-in Extensions

### Currently Available Extensions

Glueful includes these production-ready extensions:

#### 1. EmailNotification Extension
- **Type**: Core extension (always enabled)
- **Version**: 0.21.0
- **Features**: Multi-channel email notification system
- **Templates**: HTML email templates with partials
- **Channels**: Email delivery with formatting support

#### 2. SocialLogin Extension  
- **Type**: Optional extension
- **Version**: 0.18.0
- **Features**: OAuth authentication for Google, Facebook, GitHub, Apple
- **Database**: Social account linking and management
- **Configuration**: Environment-based provider setup

#### 3. Admin Extension
- **Type**: Optional extension
- **Version**: 0.18.0
- **Features**: Administrative dashboard interface
- **Capabilities**: API visualization, system monitoring, extension management
- **Interface**: Web-based admin panel

#### 4. RBAC Extension
- **Type**: Optional extension
- **Version**: 0.29.0
- **Features**: Role-based access control system
- **Database**: Hierarchical roles and permissions
- **API**: RESTful endpoints for role/permission management

#### 5. BeanstalkdQueue Extension
- **Type**: Queue driver extension
- **Version**: 1.0.0
- **Features**: High-performance job queue processing
- **Capabilities**: Tube management, priority scheduling, job statistics
- **Requirements**: Beanstalkd server installation

## Social Login Extension

### SocialLogin Extension

The SocialLogin extension provides OAuth authentication through multiple social providers:

```bash
# Enable the SocialLogin extension
php glueful extensions:enable SocialLogin
```

### Supported Providers

The extension includes these OAuth providers:

1. **Google OAuth** - Google Sign-In integration
2. **Facebook Login** - Facebook OAuth authentication
3. **GitHub OAuth** - GitHub OAuth integration  
4. **Apple Sign In** - Apple OAuth authentication

### Configuration

Configure providers in your `.env` file:

```env
# Google OAuth
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://yourapp.com/auth/google/callback

# Facebook OAuth
FACEBOOK_APP_ID=your-facebook-app-id
FACEBOOK_APP_SECRET=your-facebook-app-secret
FACEBOOK_REDIRECT_URI=https://yourapp.com/auth/facebook/callback

# GitHub OAuth
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret
GITHUB_REDIRECT_URI=https://yourapp.com/auth/github/callback

# Apple OAuth
APPLE_CLIENT_ID=your-apple-service-id
APPLE_CLIENT_SECRET=your-apple-client-secret
APPLE_TEAM_ID=your-apple-team-id
APPLE_KEY_ID=your-apple-key-id
APPLE_REDIRECT_URI=https://yourapp.com/auth/apple/callback
```

### Extension Features

- **Auto-registration**: Automatically create user accounts for new social logins
- **Account linking**: Link social accounts to existing users
- **Profile synchronization**: Sync profile data from social providers
- **Multiple providers**: Support for Google, Facebook, GitHub, and Apple
- **Database integration**: Stores social account associations

### Usage Example

The extension provides authentication routes and handles OAuth flows automatically. Use the standard authentication endpoints with social provider support:

```php
// Access social login routes (automatically registered)
// GET /auth/{provider} - Redirect to provider
// GET /auth/{provider}/callback - Handle OAuth callback

// Example: Redirect to Google OAuth
// GET /auth/google

// Example: Handle Google OAuth callback
// GET /auth/google/callback
```

### Database Migration

The extension includes a migration that automatically creates the required database table:

**Migration File**: `CreateSocialAccountsTable.php`

This migration creates the `social_accounts` table with:
- **UUID-based design**: Uses 12-character UUIDs for primary keys
- **Foreign key constraints**: Links to the users table with CASCADE delete
- **Unique constraints**: Prevents duplicate social accounts per provider
- **Profile data storage**: JSON field for storing provider-specific data
- **Proper indexing**: Optimized for lookups by user and provider

The migration runs automatically when you enable the extension or run:
```bash
php glueful migrate:run
```

## Queue Extensions

### BeanstalkdQueue Extension

The BeanstalkdQueue extension provides high-performance job queue processing:

```bash
php glueful extensions:enable BeanstalkdQueue
```

Configuration:
```env
QUEUE_CONNECTION=beanstalkd
BEANSTALKD_HOST=127.0.0.1
BEANSTALKD_PORT=11300
BEANSTALKD_QUEUE=default
BEANSTALKD_RETRY_AFTER=90
```

#### Features

- **Tube Management**: Multiple queues (tubes)
- **Priority Jobs**: 0-4294967295 priority levels
- **Delayed Jobs**: Schedule jobs for future
- **Job Statistics**: Real-time queue metrics
- **Buried Jobs**: Failed job management
- **Connection Pooling**: Reuse connections

#### Usage

```php
use Glueful\Extensions\BeanstalkdQueue\BeanstalkdQueue;

// Push job to queue
$queue->push('process-email', [
    'to' => 'user@example.com',
    'subject' => 'Welcome!'
], 'emails');

// Push with priority
$queue->pushWithPriority('urgent-job', $data, 1024);

// Delay job by 5 minutes
$queue->later(300, 'scheduled-job', $data);

// Process jobs
$queue->work('emails', function($job) {
    // Process job
    $job->delete(); // Remove from queue
});
```

#### Monitoring

```bash
# Queue statistics
php glueful queue:stats --driver=beanstalkd

# List tubes
php glueful queue:tubes

# Peek at jobs
php glueful queue:peek --tube=emails
```

### Custom Queue Driver

```php
namespace Glueful\Extensions\MyQueue;

use Glueful\Queue\Contracts\Queue;

class MyQueueDriver implements Queue
{
    public function push(string $job, array $data = [], string $queue = null): string
    {
        // Implementation
    }
    
    public function pop(string $queue = null): ?Job
    {
        // Implementation
    }
}
```

## RBAC Extension

### Role-Based Access Control System

The RBAC extension provides comprehensive role and permission management:

```bash
# Enable the RBAC extension
php glueful extensions:enable RBAC
```

### Key Features

- **Hierarchical Roles**: Multi-level role inheritance system
- **Direct User Permissions**: Override role permissions for specific users
- **Resource-Level Filtering**: Granular permission control
- **Temporal Permissions**: Time-based permission expiry
- **Audit Trail**: Comprehensive logging of all RBAC operations
- **Multi-Layer Caching**: Performance-optimized permission checking
- **Scoped Permissions**: Multi-tenancy support

### Database Structure

The extension creates these tables:
- `roles` - Role definitions
- `permissions` - Permission definitions
- `user_roles` - User-role assignments
- `user_permissions` - Direct user permissions
- `role_permissions` - Role-permission mappings

### Default System Roles

The extension creates these default roles:

1. **RBAC Administrator** (`rbac_admin`)
   - Full access to RBAC system management
   - Can manage all roles, permissions, and assignments

2. **Role Manager** (`role_manager`)
   - Can create, update, and assign roles
   - Limited to role management operations

3. **Permission Manager** (`permission_manager`)
   - Can assign and revoke permissions
   - Limited to permission management operations

### Default System Permissions

The extension includes comprehensive RBAC permissions:

**Role Management**:
- `rbac.roles.view` - View roles and role information
- `rbac.roles.create` - Create new roles
- `rbac.roles.update` - Update existing roles
- `rbac.roles.delete` - Delete roles
- `rbac.roles.assign` - Assign roles to users
- `rbac.roles.revoke` - Revoke roles from users
- `rbac.roles.manage` - Full role management access

**Permission Management**:
- `rbac.permissions.view` - View permissions
- `rbac.permissions.create` - Create new permissions
- `rbac.permissions.update` - Update permissions
- `rbac.permissions.delete` - Delete permissions
- `rbac.permissions.assign` - Assign permissions to users
- `rbac.permissions.revoke` - Revoke permissions from users
- `rbac.permissions.manage` - Full permission management

**User Management**:
- `rbac.users.view` - View user role and permission assignments
- `rbac.users.manage` - Manage user assignments

### Configuration Options

The extension supports extensive configuration:

```php
// Key configuration options in config.php
'permissions' => [
    'cache_enabled' => true,
    'cache_ttl' => 3600, // 1 hour
    'inheritance_enabled' => true,
    'temporal_permissions' => true,
    'resource_filtering' => true,
    'scoped_permissions' => true
],

'roles' => [
    'max_hierarchy_depth' => 10,
    'inherit_permissions' => true,
    'allow_circular_references' => false,
    'system_roles_protected' => true
],

'security' => [
    'require_authentication' => true,
    'audit_trail' => true,
    'permission_inheritance_check' => true
]
```

### API Endpoints

The extension provides RESTful API endpoints:

```bash
# Role management
GET    /rbac/roles          # List roles
POST   /rbac/roles          # Create role
GET    /rbac/roles/{id}     # Get role details
PUT    /rbac/roles/{id}     # Update role
DELETE /rbac/roles/{id}     # Delete role

# Permission management
GET    /rbac/permissions    # List permissions
POST   /rbac/permissions    # Create permission
GET    /rbac/permissions/{id} # Get permission details
PUT    /rbac/permissions/{id} # Update permission
DELETE /rbac/permissions/{id} # Delete permission

# User role/permission management
GET    /rbac/users/{id}/roles       # Get user roles
POST   /rbac/users/{id}/roles       # Assign role to user
DELETE /rbac/users/{id}/roles/{roleId} # Remove role from user
GET    /rbac/users/{id}/permissions # Get user permissions
POST   /rbac/users/{id}/permissions # Assign permission to user
```

### Migrations

The extension includes three migrations:
1. `001_CreateRolesTables.php` - Creates roles and user_roles tables
2. `002_CreatePermissionsTables.php` - Creates permissions and mapping tables
3. `003_SeedDefaultRoles.php` - Seeds default roles and permissions

## Extension Development

### Extension Structure

Each extension follows this standard structure:

```
MyExtension/
├── MyExtension.php        # Main extension class (matches directory name)
├── extension.json         # Extension metadata
├── composer.json          # Dependencies
├── src/
│   ├── Controllers/       # Extension controllers
│   ├── Services/          # Business logic services
│   ├── Models/            # Data models
│   ├── Repositories/      # Data repositories
│   ├── Providers/         # Service providers (for auth extensions)
│   ├── config.php         # Configuration
│   └── routes.php         # Route definitions
├── migrations/            # Database migrations
├── assets/
│   └── icon.png          # Extension icon
├── CHANGELOG.md          # Version history
└── README.md             # Documentation
```

### Extension Class Example

```php
namespace Glueful\Extensions\MyExtension;

use Glueful\Extensions;
use Glueful\IExtensions;

class MyExtension extends Extensions implements IExtensions
{
    public static function process(array $queryParams, array $bodyParams): array
    {
        // Handle extension-specific API calls
        return ['status' => 'success'];
    }
    
    public static function init(): void
    {
        // Initialize extension services and dependencies
    }
}
```

## Performance

### Performance Metrics

The extension system is optimized for production use:

- **Load Time**: Extensions load in <1ms with pre-computed autoload mappings
- **Memory Usage**: Minimal memory overhead (~26KB per extension)
- **Caching**: Multi-layer caching for configuration and autoload data
- **Dependency Resolution**: Efficient dependency validation and loading order

### Optimization Tips

1. **Enable Caching**
   ```env
   EXTENSION_CACHE_ENABLED=true
   ```

2. **Optimize Autoloading**
   ```bash
   composer dump-autoload --optimize
   ```

3. **Validate Configuration**
   ```bash
   php glueful extensions:validate-config
   ```

4. **Monitor Performance**
   ```bash
   php glueful extensions:benchmark
   ```

## Troubleshooting

### Common Issues

#### Extension Not Loading

```bash
# Check if enabled
php glueful extensions:info ExtensionName

# Validate configuration
php glueful extensions:validate ExtensionName

# Debug information
php glueful extensions:debug
```

#### Class Not Found Errors

```bash
# Check namespace mappings
php glueful extensions:info --namespaces

# Verify autoload configuration
composer dump-autoload
```

#### Permission Errors

```bash
# Check file permissions
ls -la extensions/

# Fix permissions
chmod -R 755 extensions/
chown -R www-data:www-data extensions/
```

### Debug Mode

Enable debug mode for detailed error information:

```env
EXTENSION_DEBUG=true
EXTENSION_LOG_LEVEL=debug
```

### Getting Help

```bash
# Command help
php glueful extensions:info --help

# System diagnostics
php glueful extensions:debug

# Performance analysis
php glueful extensions:benchmark
```

## Best Practices

### 1. Follow Standards
- Use PSR-4 autoloading
- Follow PSR-12 coding standards
- Use semantic versioning

### 2. Declare Dependencies
- List all required extensions
- Specify version constraints
- Document requirements

### 3. Test Thoroughly
- Write unit tests
- Test with different configurations
- Validate before production

### 4. Document Your Extension
- Clear README.md
- API documentation
- Configuration examples

### 5. Performance Considerations
- Lazy load services
- Cache when appropriate
- Profile regularly

## Summary

The Glueful Extension System provides:

- **🚀 Lightning Fast**: <1ms load times
- **💾 Memory Efficient**: ~26KB overhead
- **🔧 Developer Friendly**: Comprehensive tooling
- **📦 Modular**: Clean dependency management
- **🛡️ Secure**: Validated and sandboxed
- **📊 Observable**: Built-in monitoring

For more information, see the [API Reference](/docs/API.md) or run `php glueful help extensions`.