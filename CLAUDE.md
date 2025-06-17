# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Glueful is a modern PHP 8.2+ API framework with enterprise-grade features:
- RESTful API endpoints with OpenAPI/Swagger documentation
- JWT-based authentication with dual-layer session storage (database + cache)
- Dependency Injection (DI) container for service management
- Role-based access control (RBAC) with fine-grained permissions
- Database connection pooling and query optimization
- Extension system v2.0 (high-performance modular architecture)
- Advanced notification system with multiple channels
- Queue system with batch processing and retry mechanisms
- Archive system for data retention and compliance
- Performance optimization with memory management
- Comprehensive security features (rate limiting, vulnerability scanning, lockdown mode)

## Quick Start Commands

### Initial Setup
```bash
# Clone repository
git clone https://github.com/glueful/glueful.git
cd glueful

# Install dependencies
composer install

# Configure environment
cp .env.example .env
# Edit .env with database credentials

# Create database (MySQL example)
mysql -u root -p
CREATE DATABASE glueful CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'glueful_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON glueful.* TO 'glueful_user'@'localhost';
FLUSH PRIVILEGES;

# Run migrations
php glueful migrate run

# Initialize DI container and cache
php glueful cache clear
php glueful di:container:validate

# Start development server
php glueful serve
# Server runs at http://localhost:8000
```

## CLI Commands Reference

### Database Management
```bash
# Migrations
php glueful migrate run                # Run pending migrations
php glueful migrate:rollback           # Rollback last migration
php glueful migrate:status             # Check migration status
php glueful migrate:create <name>      # Create new migration

# Database operations
php glueful db:status                  # Check database status
php glueful db:reset --force           # Reset database (destructive)
php glueful db:seed                    # Run database seeders
php glueful db:fresh                   # Drop all tables and re-run migrations

# Connection pool management
php glueful db:pool:status             # Check connection pool status
php glueful db:pool:reset              # Reset connection pool
php glueful db:pool:health             # Health check for pooled connections

# Query optimization
php glueful db:query:profile           # Profile database queries
php glueful db:query:analyze           # Analyze query performance
php glueful db:query:optimize          # Suggest query optimizations
```

### Extension Management
```bash
# List and info
php glueful extensions list                    # List all extensions
php glueful extensions list --show-autoload    # Show with autoload info
php glueful extensions info ExtensionName      # Get extension details

# Enable/disable
php glueful extensions enable ExtensionName    # Enable an extension
php glueful extensions disable ExtensionName   # Disable an extension

# Development
php glueful extensions create MyExtension      # Create new extension
php glueful extensions validate ExtensionName  # Validate extension
php glueful extensions benchmark               # Benchmark performance
php glueful extensions debug                   # Debug extension system
php glueful extensions namespaces              # Show loaded namespaces
```

### Cache Management
```bash
# Basic operations
php glueful cache clear                        # Clear all cache
php glueful cache clear --tag=<tag>            # Clear cache by tag
php glueful cache status                       # View cache statistics
php glueful cache flush                        # Flush entire cache

# Advanced cache
php glueful cache:edge:purge                   # Purge edge cache
php glueful cache:warm                         # Warm up application cache
php glueful cache:analyze                      # Analyze cache usage

# Cache operations
php glueful cache get <key>                    # Get cache value
php glueful cache set <key> <value> [<ttl>]    # Set cache value
php glueful cache delete <key>                 # Delete cache key
```

### Queue Management
```bash
# Worker commands
php glueful queue:work [driver] [--queue=default]      # Process queue jobs
php glueful queue:listen [driver] [--queue=default]    # Listen for new jobs
php glueful queue:batch:process                        # Process batch jobs

# Monitoring
php glueful queue:failed                               # List failed jobs
php glueful queue:retry <id>                           # Retry a failed job
php glueful queue:flush                                # Clear all queued jobs
php glueful queue:stats                                # View queue statistics
php glueful queue:health                               # Check queue health
```

### Notification System
```bash
# Process notifications
php glueful notifications:send                         # Send pending notifications
php glueful notifications:retry                        # Retry failed notifications
php glueful notifications:cleanup                      # Clean old notifications

# Management
php glueful notifications:channels                     # List available channels
php glueful notifications:templates                    # List notification templates
php glueful notifications:metrics                      # View notification metrics
```

### Archive System
```bash
# Archive operations
php glueful archive:run                                # Run archival process
php glueful archive:restore <id>                       # Restore archived data
php glueful archive:search                             # Search archived data

# Archive management
php glueful archive:stats                              # View archive statistics
php glueful archive:health                             # Check archive health
php glueful archive:cleanup                            # Clean old archives
```

### Performance Management
```bash
# Memory monitoring
php glueful performance:memory                         # Show memory usage
php glueful performance:memory:alert                   # Check memory alerts
php glueful performance:memory:optimize                # Optimize memory usage

# Performance profiling
php glueful performance:profile                        # Profile application
php glueful performance:benchmark                      # Run benchmarks
```

### Security Commands
```bash
# Security scanning
php glueful security:scan                              # Run security scan
php glueful security:vulnerabilities                   # Check vulnerabilities
php glueful security:headers:check                     # Verify security headers

# Rate limiting
php glueful security:ratelimit:status                  # Check rate limit status
php glueful security:ratelimit:reset                   # Reset rate limits

# Lockdown mode
php glueful security:lockdown:enable                   # Enable lockdown mode
php glueful security:lockdown:disable                  # Disable lockdown mode
```

### DI Container Commands
```bash
# Container management
php glueful di:container:validate                      # Validate container config
php glueful di:container:services                      # List registered services
php glueful di:container:debug                         # Debug container issues
```

### System Commands
```bash
# Help and documentation
php glueful help                                       # Show all commands
php glueful help migrate run                           # Show specific command help

# System operations
php glueful system:check                               # System health check
php glueful key:generate                               # Generate security key

# API documentation
php glueful generate:json api-definitions -d mydb -T users  # Generate JSON definitions
php glueful generate:json doc                               # Generate API documentation
```

## Testing Commands

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration

# Run with coverage
composer test:coverage

# Code quality checks
composer phpcs                 # Check code standards
composer phpcbf                # Fix code standards automatically
vendor/bin/phpstan             # Static analysis (level 5)

# Run specific tests
./vendor/bin/phpunit tests/Unit/Auth/TokenStorageServiceTest.php
./vendor/bin/phpunit --filter testStoreSession
```

## Development Workflows

### Working with DI Container

1. **Register a new service**
   ```php
   // In api/DI/ServiceProviders/MyServiceProvider.php
   public function register(): void
   {
       $this->container->singleton(MyService::class, function($container) {
           return new MyService(
               $container->get(DatabaseInterface::class),
               $container->get(CacheInterface::class)
           );
       });
   }
   ```

2. **Access services**
   ```php
   // Using global container
   $service = container()->get(MyService::class);
   
   // Using dependency injection
   public function __construct(
       private MyService $myService
   ) {}
   ```

### Creating a New Feature

1. **Create feature branch**
   ```bash
   git checkout -b feature/my-feature
   ```

2. **Create necessary files**
   - Controller: `api/Controllers/MyController.php`
   - Service: `api/Services/MyService.php`
   - Repository: `api/Repository/MyRepository.php`
   - Model/DTO: `api/DTOs/MyDTO.php`
   - Routes: Add to `routes/my-routes.php`
   - Service Provider: `api/DI/ServiceProviders/MyServiceProvider.php`

3. **Register services in DI container**
   ```php
   // In bootstrap.php, add your service provider
   $providers = [
       // ... existing providers
       \Glueful\DI\ServiceProviders\MyServiceProvider::class,
   ];
   ```

4. **Create database migration**
   ```bash
   # Create migration file in database/migrations/
   # Name format: XXX_CreateMyTable.php
   ```

5. **Run tests**
   ```bash
   composer test
   composer phpcs
   vendor/bin/phpstan
   ```

### Working with Sessions and Authentication

JWT-based authentication with dual-layer session storage (database + cache). Key service: `TokenStorageService`.

#### Basic Session Operations

```php
use Glueful\Auth\TokenStorageService;
$tokenStorage = container()->get(TokenStorageService::class);

// Store session with metadata
$sessionData = ['uuid' => $userUuid, 'username' => $username, 'email' => $email];
$tokens = ['access_token' => $accessToken, 'refresh_token' => $refreshToken];
$success = $tokenStorage->storeSession($sessionData, $tokens);

// Retrieve and validate sessions
$session = $tokenStorage->getSessionByAccessToken($accessToken);
$isValid = $tokenStorage->isSessionValid($accessToken);

// Session management
$tokenStorage->revokeSession($accessToken);
$tokenStorage->revokeAllUserSessions($userUuid);
$cleanedCount = $tokenStorage->cleanupExpiredSessions();
```

#### Advanced Session Features

- **Query Builder**: Use `$tokenStorage->queryBuilder()` for complex session queries
- **Analytics**: Session metrics, security monitoring, suspicious activity detection  
- **Cache Integration**: Automatic cache-first pattern with configurable TTL
- **Transaction Safety**: Set `setUseTransactions(true)` for atomic operations

### Working with Notifications

Multi-channel notification system with template management, scheduling, and retry mechanisms.

#### Basic Usage

```php
use Glueful\Notifications\NotificationService;
$notificationService = container()->get(NotificationService::class);

// Send notification
$result = $notificationService->send(
    'user_welcome',                    // type
    $notifiableUser,                   // recipient (implements Notifiable)
    'Welcome to our platform!',       // subject
    ['username' => $user->name],       // data
    ['channels' => ['email', 'database']] // options
);

// Template-based notifications
$result = $notificationService->sendWithTemplate(
    'password_reset', $notifiableUser, 'password-reset-template',
    ['reset_url' => $resetUrl], ['channels' => ['email']]
);

// Scheduled notifications
$notificationService->create(
    'subscription_reminder', $user, 'Your subscription expires soon',
    ['expiry_date' => $expiryDate],
    ['channels' => ['email'], 'schedule' => new DateTime('+7 days')]
);
```

#### Key Features

- **Channel Priority**: Explicit channels → User preferences → Default configuration
- **Templates**: Channel-specific templates via `TemplateManager`
- **Retry Logic**: Automatic retry with exponential backoff via `NotificationRetryService`
- **Repository**: Query notifications with `NotificationRepository`
- **Analytics**: Delivery rates, open rates, channel performance
- **Custom Channels**: Extend `AbstractChannel` for Slack, Discord, webhooks

### Performance Optimization

Comprehensive performance tools including chunked processing, memory management, and monitoring.

#### Chunked Database Processing

```php
use Glueful\Performance\ChunkedDatabaseProcessor;

$processor = new ChunkedDatabaseProcessor($connection, 1000);

// Query-based chunking
$totalProcessed = $processor->processSelectQuery(
    "SELECT * FROM users WHERE status = ? AND created_at > ?",
    function($rows) { /* process chunk */ return count($rows); },
    ['active', '2024-01-01'], 500
);

// Table-based chunking (more efficient for large tables)
$results = $processor->processTableInChunks(
    'large_transactions', $chunkProcessor, 'id', 
    ['status' => 'pending'], 2000, 'created_at'
);
```

#### Memory Management

```php
use Glueful\Performance\MemoryManager;
use Glueful\Performance\MemoryPool;

$memoryManager = new MemoryManager($logger);
$usage = $memoryManager->monitor(); // Returns current, limit, percentage

// Threshold monitoring
if ($memoryManager->isMemoryWarning()) { /* handle */ }
if ($memoryManager->isMemoryCritical()) { /* force cleanup */ }

// Resource pooling
$memoryPool = new MemoryPool();
$resource = $memoryPool->acquire('database_connections');
```

## Project Structure

```
glueful/
├── api/                      # Core API framework
│   ├── Auth/                 # Authentication (JWT, sessions, tokens)
│   ├── Cache/                # Caching with edge support and CDN
│   ├── Console/              # CLI commands and runners
│   ├── Controllers/          # API controllers
│   ├── Database/             # Database layer with connection pooling
│   ├── DI/                   # Dependency injection container
│   │   └── ServiceProviders/ # Service provider classes
│   ├── Http/                 # HTTP handling and middleware
│   ├── Models/               # Data models
│   ├── Notifications/        # Multi-channel notification system
│   ├── Performance/          # Memory management and optimization
│   ├── Permissions/          # RBAC and authorization
│   ├── Queue/                # Queue system with batch processing
│   ├── Repository/           # Data repositories
│   ├── Security/             # Security features and scanners
│   ├── Services/             # Business logic services
│   │   └── Archive/          # Archive system implementation
│   └── Validation/           # Input validation and sanitization
├── config/                   # Configuration files
│   ├── app.php              # Application config
│   ├── archive.php          # Archive settings
│   ├── cache.php            # Cache configuration
│   ├── database.php         # Database settings
│   ├── lockdown.php         # Security lockdown
│   ├── queue.php            # Queue configuration
│   ├── schedule.php         # Job scheduling
│   └── session.php          # Session and JWT settings
├── database/                 # Migrations and seeds
│   └── migrations/          # Database migrations
├── docs/                     # Documentation
├── extensions/               # Installed extensions
├── public/                   # Public assets
├── routes/                   # API route definitions
├── storage/                  # Storage directory
│   ├── app/                 # Application storage
│   ├── cache/               # Cache files
│   ├── logs/                # Log files
│   └── sessions/            # Session files
├── tests/                    # Test suites
│   ├── Unit/                # Unit tests
│   └── Integration/         # Integration tests
├── bootstrap.php             # DI container initialization
├── composer.json             # PHP dependencies
├── phpunit.xml              # PHPUnit configuration
├── phpstan.neon             # PHPStan configuration
└── glueful                  # CLI executable
```

## Environment Configuration

Key environment variables in `.env`:

```env
# Application
APP_ENV=development                    # development|production
APP_DEBUG=true                         # Auto-disabled in production
API_DOCS_ENABLED=true                  # Auto-disabled in production

# Security
JWT_KEY=your-secure-key                # Generate with: php glueful key:generate
ACCESS_TOKEN_LIFETIME=900              # 15 minutes
REFRESH_TOKEN_LIFETIME=604800          # 7 days
SESSION_LIFETIME=86400                 # 24 hours
REMEMBER_ME_LIFETIME=2592000           # 30 days

# Database
DB_DRIVER=mysql
DB_HOST=localhost
DB_DATABASE=glueful
DB_USER=glueful_user
DB_PASSWORD=your_password

# Connection pooling
DB_POOL_ENABLED=true
DB_POOL_MIN_CONNECTIONS=5
DB_POOL_MAX_CONNECTIONS=20

# Cache & Queue
CACHE_DRIVER=redis                     # redis|file
CACHE_PREFIX=glueful_
QUEUE_CONNECTION=redis                 # redis|database|beanstalkd

# Performance
MEMORY_LIMIT_WARNING=128M
MEMORY_LIMIT_CRITICAL=256M
CHUNK_SIZE=1000

# Features
ENABLE_PERMISSIONS=true
ENABLE_NOTIFICATIONS=true
ENABLE_ARCHIVE=true
API_DEBUG_MODE=true

# Security features
RATE_LIMIT_ENABLED=true
RATE_LIMIT_ADAPTIVE=true
SECURITY_HEADERS_ENABLED=true
LOCKDOWN_ENABLED=false
```

## Common Development Tasks

### Adding a New API Endpoint

1. **Create controller method**
   ```php
   namespace Glueful\Controllers;
   
   class MyController extends BaseController
   {
       public function __construct(
           private MyService $myService
       ) {}
       
       public function myEndpoint(Request $request): Response
       {
           $validated = $this->validate($request, [
               'field' => 'required|string|max:255'
           ]);
           
           $result = $this->myService->process($validated);
           
           return $this->json($result);
       }
   }
   ```

2. **Add route with OpenAPI documentation**
   ```php
   // In routes/my-routes.php
   use Glueful\Core\Router;
   
   /**
    * @route POST /my-endpoint
    * @summary Process my endpoint
    * @description Processes data through my custom endpoint
    * @tag MyModule
    * @requestBody field:string="Input field description"
    * {required=field}
    * @response 200 application/json "Success" {
    *   success:boolean="Success status",
    *   message:string="Success message",
    *   data:object="Processed result data"
    * }
    * @response 400 "Validation error"
    * @response 401 "Unauthorized"
    */
   Router::post('/my-endpoint', [MyController::class, 'myEndpoint'], requiresAuth: true);
   ```

3. **Register controller in DI**
   ```php
   // In api/DI/ServiceProviders/ControllerServiceProvider.php
   $this->container->singleton(MyController::class);
   ```

4. **Generate and test API documentation**
   ```bash
   # Generate updated Swagger documentation
   php glueful generate:json doc
   
   # Test the endpoint
   curl -X POST http://localhost:8000/my-endpoint \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer your-jwt-token" \
     -d '{"field": "test value"}'
   ```

### Working with Database

Custom QueryBuilder with fluent interface and connection pooling support.

#### Basic Operations

```php
$db = container()->get(DatabaseInterface::class);

// CRUD operations
$users = $db->select('users', ['id', 'username', 'email'])
    ->where(['status' => 'active'])->orderBy(['created_at' => 'DESC'])->limit(10)->get();

$affected = $db->insert('users', ['uuid' => Utils::generateNanoID(), 'username' => 'john_doe']);
$affected = $db->update('users', ['email' => 'new@example.com'], ['uuid' => $userUuid]);
$success = $db->delete('users', ['uuid' => $uuid], true); // soft delete
```

#### Advanced Queries

```php
// Advanced queries
$results = $db->select('posts', ['posts.title', 'users.username'])
    ->join('users', 'users.id = posts.user_id', 'INNER')
    ->whereIn('role', ['admin', 'user'])->paginate($page, $perPage);
```

#### Repository Pattern (Recommended)

```php
$userRepo = container()->get(UserRepository::class);

// Basic operations
$user = $userRepo->findById($uuid);
$users = $userRepo->findWhere(['status' => 'active']);
$result = $userRepo->paginate($page, $perPage, ['status' => 'active']);

// Custom repository methods
class UserRepository extends BaseRepository {
    protected string $table = 'users';
    public function searchUsers(string $term): array {
        return $this->db->select($this->table, ['*'])
            ->search(['username', 'email', 'name'], $term, 'OR')->get();
    }
}
```

#### Advanced Features

- **Transactions**: Use `$db->transaction(callable)` or manual `beginTransaction()`/`commit()`
- **Connection Pooling**: Automatic with `ConnectionPoolManager`
- **Query Profiling**: Use `QueryProfiler` for performance analysis
- **Bulk Operations**: `insertBatch()`, `bulkUpdate()` for large datasets

### Working with Cache

CacheEngine supports Redis, Memcached, file-based caching, plus CDN/edge cache integration.

#### Basic Cache Operations

```php
use Glueful\Cache\CacheEngine;

// Basic operations
CacheEngine::set('user:123', $userData, 3600); // TTL in seconds
$userData = CacheEngine::get('user:123');
CacheEngine::delete('user:123');
CacheEngine::increment('page_views', 1);

// Remember pattern for expensive operations
$activeUsers = CacheEngine::remember('active-users', 3600, function() {
    return $this->userRepo->findWhere(['status' => 'active']);
});
```

#### Key Features

- **Naming Patterns**: `session_token:{token}`, `user_permissions:{uuid}`, `rate_limit:{id}`
- **Tagged Cache**: Group invalidation with `addTags()` and `invalidateTags()`
- **Pattern Deletion**: Use `deletePattern("session:*")` for bulk cleanup
- **Edge Cache**: CDN integration via `EdgeCacheService`
- **Multiple Drivers**: Use `CacheFactory::create()` for specific drivers

### Working with Routes

Custom Router built on Symfony routing with PSR-15 middleware support.

#### Basic Usage

```php
use Glueful\Core\Router;

// HTTP methods with controllers
Router::get('/users/{id}', [UserController::class, 'show']);
Router::post('/users', [UserController::class, 'create'], requiresAuth: true);
Router::put('/users/{id}', [UserController::class, 'update'], requiresAuth: true);
Router::delete('/users/{id}', [UserController::class, 'destroy'], requiresAuth: true, requiresAdminAuth: true);

// Route grouping
Router::group('/admin', function() {
    Router::get('/users', [AdminController::class, 'listUsers']);
    Router::post('/users', [AdminController::class, 'createUser']);
}, requiresAuth: true, requiresAdminAuth: true);
```

#### Key Features

- **Parameters**: Path params `{id}`, query params via `$request->query->get()`
- **Authentication**: `requiresAuth: true`, `requiresAdminAuth: true`
- **Middleware**: Global via `addMiddleware()` or per-route
- **Extensions**: Can define routes in `extensions/Name/routes.php`

#### OpenAPI Documentation

Use custom annotations to generate Swagger JSON:

```php
/**
 * @route POST /auth/login
 * @summary User Login
 * @tag Authentication
 * @requestBody username:string password:string {required=username,password}
 * @response 200 "Login successful" @response 401 "Invalid credentials"
 */
Router::post('/auth/login', [AuthController::class, 'login']);
```

**Key Annotations**: `@route`, `@summary`, `@tag`, `@requestBody`, `@response`

**Generate Documentation**: `php glueful generate:json doc`

## Debugging Tips

1. **Enable debug mode**: Set `APP_DEBUG=true`, `API_DEBUG_MODE=true` in `.env`
2. **Check logs**: `tail -f storage/logs/app-*.log` (app, debug, query, error logs)
3. **Query debugging**: `$db->enableQueryLog()`, use `QueryProfiler`
4. **Performance**: `MemoryManager->getCurrentUsage()`, `Timer` for execution time
5. **DI container**: `php glueful di:container:debug`

## Production Deployment

1. **Environment**: Set `APP_ENV=production`, `APP_DEBUG=false`, `API_DOCS_ENABLED=false`
2. **Security**: Generate JWT_KEY, strong passwords, configure CORS, enable HTTPS, rate limits
3. **Performance**: `php glueful cache:warm`, `composer install --optimize-autoloader --no-dev`
4. **Database**: Enable connection pooling, configure pool sizes
5. **Monitoring**: Use `/health` endpoint, monitor logs, set up alerts

## Quick Reference

### File Naming Conventions
- Controllers: `PascalCase` + `Controller.php`
- Services: `PascalCase` + `Service.php`
- Repositories: `PascalCase` + `Repository.php`
- Service Providers: `PascalCase` + `ServiceProvider.php`
- Migrations: `XXX_Description.php` (XXX = sequential number)
- Extensions: `PascalCase` directory and main class

### Code Standards
- PSR-12 coding standard
- PHP 8.2+ features (typed properties, attributes, enums)
- Type declarations required
- Constructor property promotion preferred
- PHPDoc for complex logic
- Return type declarations mandatory

### Git Workflow
```bash
# Feature branch
git checkout -b feature/description

# Commit format
git commit -m "feat: add new feature"
git commit -m "fix: resolve issue"
git commit -m "docs: update documentation"
git commit -m "refactor: improve code structure"
git commit -m "test: add unit tests"
git commit -m "perf: optimize performance"
git commit -m "chore: update dependencies"

# Push and create PR
git push origin feature/description
```

## Common Issues & Solutions

### DI Container Issues
- Service not found: `php glueful di:container:services | grep MyService`
- Circular dependency: `php glueful di:container:debug`
- Clear cache: `php glueful cache:clear`

### Session/Authentication Problems
- Verify JWT_KEY is set and sessions table exists (migration 005)
- Check token expiration settings and cache connectivity

### Performance Issues
- Memory: `php glueful performance:memory`
- Queries: `php glueful db:query:profile`
- Connection pool: `php glueful db:pool:status`

### Queue/Extension Issues
- Queue health: `php glueful queue:health`
- Extension validation: `php glueful extensions validate ExtensionName`