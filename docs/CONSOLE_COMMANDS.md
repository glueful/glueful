# Glueful Console Commands

This comprehensive guide covers Glueful's console system, built on Symfony Console with enhanced features for modern PHP development and enterprise-grade application management.

## Table of Contents

1. [Overview](#overview)
2. [Console Architecture](#console-architecture)
3. [Command Categories](#command-categories)
4. [Development Commands](#development-commands)
5. [Database Management](#database-management)
6. [Cache Operations](#cache-operations)
7. [Extension Management](#extension-management)
8. [Queue Management](#queue-management)
9. [Security Commands](#security-commands)
10. [System Utilities](#system-utilities)
11. [Code Generation](#code-generation)
12. [Custom Commands](#custom-commands)
13. [Best Practices](#best-practices)

## Overview

Glueful's console system provides a powerful command-line interface for application management, built on Symfony Console with dependency injection integration and enhanced features for modern development workflows.

### Key Features

- **Symfony Console Integration**: Modern CLI framework with advanced features
- **Dependency Injection**: Full DI container integration for all commands
- **Enhanced Styling**: SymfonyStyle integration with custom styling helpers
- **Interactive Commands**: Rich user interaction with confirmations, choices, and progress bars
- **Production Safety**: Built-in production environment safeguards
- **Extensible Architecture**: Easy custom command creation with base classes
- **Multi-format Output**: Support for table, JSON, and compact output formats

### Console Architecture

The console system is built around several key components:

1. **Application**: Central console application managing all commands
2. **BaseCommand**: Enhanced base class with DI integration and styling
3. **Command Registry**: Automatic command discovery and registration
4. **Service Integration**: Full access to application services and utilities

## Console Architecture

### Application Class

```php
use Glueful\Console\Application;

$container = app();
$app = new Application($container);
$app->run();
```

**Key Features:**
- Automatic command registration via DI container
- Enhanced error handling and exception management
- Consistent branding and help system
- Command categorization and organization

### BaseCommand Class

All Glueful commands extend the enhanced BaseCommand:

```php
use Glueful\Console\BaseCommand;

class MyCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Starting operation...');
        $this->success('Operation completed!');
        return self::SUCCESS;
    }
}
```

**Enhanced Methods:**
- `$this->success($message)` - Green success message
- `$this->error($message)` - Red error message
- `$this->warning($message)` - Yellow warning message
- `$this->info($message)` - Blue info message
- `$this->note($message)` - Highlighted note
- `$this->confirm($question, $default)` - Yes/no confirmation
- `$this->ask($question, $default)` - Text input
- `$this->choice($question, $choices, $default)` - Multiple choice
- `$this->table($headers, $rows)` - Formatted table display
- `$this->progressBar($steps, $callback)` - Progress tracking

## Command Categories

### Available Commands

```bash
# View all available commands
php glueful help
php glueful list

# Get help for specific command
php glueful help migrate:run
php glueful system:check --help
```

## Development Commands

### Serve Command

Start the built-in development server:

```bash
# Start development server
php glueful serve

# Options
php glueful serve --host=0.0.0.0 --port=8080
```

**Features:**
- Hot reloading support
- Development environment optimization
- Automatic dependency checking

### Route Commands

Display and analyze application routes:

```bash
# List all routes
php glueful routes

# Show route details
php glueful routes --detailed

# Filter routes
php glueful routes --method=GET
php glueful routes --path=/api
```

## Database Management

### Migration Commands

#### Run Migrations

```bash
# Run all pending migrations
php glueful migrate:run

# Options
php glueful migrate:run --force           # Skip production confirmation
php glueful migrate:run --dry-run         # Show what would be executed
php glueful migrate:run --batch=5         # Specify batch number
```

**Features:**
- Production safety confirmations
- Progress bars for multiple migrations
- Dry-run mode for testing
- Detailed error reporting

#### Create Migrations

```bash
# Create new migration
php glueful migrate:create CreateUsersTable
php glueful migrate:create AddIndexToUsers
```

#### Migration Status

```bash
# Check migration status
php glueful migrate:status

# Show detailed information
php glueful migrate:status --detailed
```

#### Rollback Migrations

```bash
# Rollback last migration
php glueful migrate:rollback

# Rollback specific number of migrations
php glueful migrate:rollback --steps=3

# Rollback to specific batch
php glueful migrate:rollback --batch=2
```

### Database Utilities

#### Database Status

```bash
# Check database connection and status
php glueful database:status

# Show detailed connection information
php glueful database:status --detailed
```

#### Database Reset

```bash
# Reset database (destructive operation)
php glueful database:reset --force

# Reset with confirmation
php glueful database:reset
```

#### Query Profiling

```bash
# Profile database queries
php glueful database:profile

# Profile specific operations
php glueful database:profile --operation=migration
```

## Cache Operations

### Clear Cache

```bash
# Clear all cache
php glueful cache:clear

# Clear specific cache tags
php glueful cache:clear --tag=user-sessions
php glueful cache:clear --tag=api-responses --tag=compiled-views

# Force clear without confirmation
php glueful cache:clear --force
```

**Features:**
- Tag-based cache invalidation
- Production safety confirmations
- Multiple cache store support
- Automatic cache store availability testing

### Cache Status

```bash
# View cache statistics
php glueful cache:status

# Show detailed cache information
php glueful cache:status --detailed
```

### Cache Operations

```bash
# Get cache value
php glueful cache:get user:123

# Set cache value
php glueful cache:set user:123 '{"name":"John"}' --ttl=3600

# Delete cache key
php glueful cache:delete user:123

# Check cache TTL
php glueful cache:ttl user:123

# Set cache expiration
php glueful cache:expire user:123 300

# Purge cache by pattern
php glueful cache:purge "user:*"
```

## Extension Management

### Extension Information

```bash
# List all extensions
php glueful extensions:info

# Show specific extension details
php glueful extensions:info MyExtension

# Filter by status
php glueful extensions:info --status=enabled
php glueful extensions:info --status=disabled

# Different output formats
php glueful extensions:info --format=json
php glueful extensions:info --format=compact

# Show additional information
php glueful extensions:info --show-autoload
php glueful extensions:info --show-dependencies
```

### Extension Lifecycle

```bash
# Enable extension
php glueful extensions:enable MyExtension

# Disable extension
php glueful extensions:disable MyExtension

# Create new extension
php glueful extensions:create MyExtension --type=basic
php glueful extensions:create MyExtension --type=advanced

# Validate extension
php glueful extensions:validate MyExtension

# Install extension from package
php glueful extensions:install /path/to/extension.zip
php glueful extensions:install https://example.com/extension.zip

# Delete extension
php glueful extensions:delete MyExtension --force
```

### Extension Development

```bash
# Show extension namespaces
php glueful extensions:info --namespaces

# Check for namespace conflicts
php glueful extensions:info --namespaces --conflicts

# Performance metrics
php glueful extensions:info --namespaces --performance

# Filter namespaces
php glueful extensions:info --namespaces --filter="App\\*"

# Benchmark extensions
php glueful extensions:benchmark

# Debug extension system
php glueful extensions:debug
```

## Queue Management

### Queue Workers

```bash
# Start queue workers (default: 2 workers)
php glueful queue:work

# Configure worker options
php glueful queue:work --workers=4 --queue=default,high
php glueful queue:work --memory=256 --timeout=120 --max-jobs=500

# Run in daemon mode
php glueful queue:work --daemon

# Stop when queue is empty
php glueful queue:work --stop-when-empty
```

### Advanced Queue Management

```bash
# Spawn additional workers
php glueful queue:work spawn --count=2 --queue=email

# Scale workers dynamically
php glueful queue:work scale --count=5 --queue=default

# Check worker status
php glueful queue:work status
php glueful queue:work status --json
php glueful queue:work status --watch=5  # Auto-refresh every 5 seconds

# Stop workers
php glueful queue:work stop --all
php glueful queue:work stop --worker-id=worker-123

# Restart workers
php glueful queue:work restart --all
php glueful queue:work restart --worker-id=worker-123

# Health check
php glueful queue:work health
```

### Auto-scaling

```bash
# Start auto-scaling daemon
php glueful queue:autoscale

# Configure scaling parameters
php glueful queue:autoscale --min-workers=2 --max-workers=10
php glueful queue:autoscale --scale-up-threshold=10 --scale-down-threshold=2

# Monitor scaling metrics
php glueful queue:autoscale --status
```

### Queue Scheduler

```bash
# Start job scheduler
php glueful queue:scheduler

# Run specific schedule
php glueful queue:scheduler --schedule=daily
```

## Security Commands

### Security Checks

```bash
# Run comprehensive security scan
php glueful security:check

# Check for vulnerabilities
php glueful security:vulnerabilities

# Scan for security issues
php glueful security:scan --detailed

# Generate security report
php glueful security:report --format=json
```

### Security Management

```bash
# Enable lockdown mode
php glueful security:lockdown:enable

# Disable lockdown mode
php glueful security:lockdown:disable

# Reset user password
php glueful security:reset-password user@example.com

# Revoke tokens
php glueful security:revoke-tokens --user=123
php glueful security:revoke-tokens --all

# Check security headers
php glueful security:headers:check
```

## System Utilities

### System Check

```bash
# Comprehensive system validation
php glueful system:check

# Show detailed information
php glueful system:check --details

# Attempt automatic fixes
php glueful system:check --fix

# Production readiness check
php glueful system:check --production
```

**Validates:**
- PHP version and extensions
- File permissions and directories
- Configuration settings
- Database connectivity
- Security configuration
- Production environment settings

### Memory Monitoring

```bash
# Monitor memory usage
php glueful system:memory-monitor

# Set monitoring thresholds
php glueful system:memory-monitor --warning=128M --critical=256M

# Monitor specific processes
php glueful system:memory-monitor --process=queue:work
```

### Production Commands

```bash
# Prepare for production deployment
php glueful system:production

# Optimize for production
php glueful system:production --optimize

# Validate production configuration
php glueful system:production --validate
```

## Code Generation

### Generate Keys

```bash
# Generate all encryption keys
php glueful generate:key

# Generate specific keys
php glueful generate:key --jwt-only
php glueful generate:key --app-only

# Force overwrite existing keys
php glueful generate:key --force

# Show generated keys (not recommended in production)
php glueful generate:key --show
```

### Generate Controllers

```bash
# Generate basic controller
php glueful generate:controller UserController

# Generate REST API controller
php glueful generate:controller UserController --api

# Generate with specific methods
php glueful generate:controller UserController --methods=index,show,store
```

### API Documentation

```bash
# Generate API definitions
php glueful generate:api-definitions

# Generate API documentation
php glueful generate:api-docs

# Custom database and table
php glueful generate:api-definitions --database=mydb --table=users
```

## Custom Commands

### Creating Custom Commands

```php
<?php

namespace App\Console\Commands;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'my:command',
    description: 'Custom command description'
)]
class MyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Custom command description')
             ->setHelp('Detailed help text for the command')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'Name argument'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force execution'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $force = $input->getOption('force');

        // Production safety check
        if (!$force && !$this->confirmProduction('execute custom operation')) {
            return self::FAILURE;
        }

        try {
            $this->info("Starting operation for: {$name}");

            // Access services via DI container
            $myService = $this->getService(MyService::class);
            
            // Progress bar example
            $this->progressBar(10, function ($progressBar) use ($myService) {
                for ($i = 0; $i < 10; $i++) {
                    $myService->doSomething();
                    $progressBar->advance();
                    sleep(1);
                }
            });

            $this->success('Operation completed successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Operation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
```

### Registering Custom Commands

```php
// In your service provider or bootstrap file
$app = new Glueful\Console\Application($container);
$app->addCommand(MyCommand::class);
```

### Command Templates

Glueful provides templates for common command patterns:

```bash
# Create command from template
php glueful generate:command MyCommand --template=basic
php glueful generate:command MyCommand --template=database
php glueful generate:command MyCommand --template=interactive
```

## Best Practices

### Command Design

1. **Use Descriptive Names**: Follow the `group:action` pattern
2. **Provide Good Help**: Include detailed descriptions and examples
3. **Handle Errors Gracefully**: Use try-catch blocks and meaningful error messages
4. **Production Safety**: Always check production environment for destructive operations
5. **Progress Feedback**: Use progress bars for long-running operations

### Input Validation

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Validate required options
    $email = $input->getOption('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address provided');
        return self::FAILURE;
    }

    // Validate file paths
    $file = $input->getArgument('file');
    if (!file_exists($file)) {
        $this->error("File not found: {$file}");
        return self::FAILURE;
    }

    return self::SUCCESS;
}
```

### Interactive Commands

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Confirmation prompts
    if (!$this->confirm('Do you want to continue?', false)) {
        $this->info('Operation cancelled');
        return self::SUCCESS;
    }

    // Choice menus
    $action = $this->choice(
        'What would you like to do?',
        ['create', 'update', 'delete'],
        'create'
    );

    // Text input with validation
    $name = $this->ask('Enter name');
    while (empty($name)) {
        $this->warning('Name cannot be empty');
        $name = $this->ask('Enter name');
    }

    return self::SUCCESS;
}
```

### Output Formatting

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Table display
    $this->table(
        ['ID', 'Name', 'Status'],
        [
            [1, 'John Doe', 'Active'],
            [2, 'Jane Smith', 'Inactive']
        ]
    );

    // Progress tracking
    $items = range(1, 100);
    $this->progressBar(count($items), function ($progressBar) use ($items) {
        foreach ($items as $item) {
            // Process item
            sleep(0.1);
            $progressBar->advance();
        }
    });

    return self::SUCCESS;
}
```

### Error Handling

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    try {
        // Risky operation
        $result = $this->performOperation();
        
        if (!$result) {
            $this->warning('Operation completed with warnings');
            return self::SUCCESS;
        }
        
        $this->success('Operation completed successfully');
        return self::SUCCESS;
    } catch (ValidationException $e) {
        $this->error('Validation failed: ' . $e->getMessage());
        return self::INVALID;
    } catch (\Exception $e) {
        $this->error('Unexpected error: ' . $e->getMessage());
        
        if ($input->getOption('verbose')) {
            $this->line($e->getTraceAsString());
        }
        
        return self::FAILURE;
    }
}
```

### Service Integration

```php
class MyCommand extends BaseCommand
{
    private MyService $myService;
    private DatabaseInterface $database;

    public function __construct()
    {
        parent::__construct();
        
        // Resolve services from DI container
        $this->myService = $this->getService(MyService::class);
        $this->database = $this->getService(DatabaseInterface::class);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Use services
        $users = $this->database->select('users')->get();
        $result = $this->myService->processUsers($users);

        return self::SUCCESS;
    }
}
```

### Configuration and Environment

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // Check environment
    if ($this->isProduction() && !$input->getOption('force')) {
        if (!$this->confirmProduction('perform this operation')) {
            return self::FAILURE;
        }
    }

    // Access configuration
    $timeout = config('app.timeout', 30);
    $debug = config('app.debug', false);

    return self::SUCCESS;
}
```

This comprehensive console system provides powerful tools for application management, development workflows, and system administration while maintaining consistency, safety, and ease of use.