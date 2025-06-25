# Symfony OptionsResolver Integration - Usage Guide

## Overview

Glueful now uses Symfony OptionsResolver for robust configuration validation. This provides type-safe configuration with validation, normalization, and sensible defaults across all services.

## Quick Start

### Basic Service Configuration

```php
use Glueful\Config\ConfigurableService;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MyService extends ConfigurableService
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'timeout' => 30,
            'retries' => 3,
            'debug' => false,
        ]);
        
        $resolver->setRequired(['api_key']);
        
        $resolver->setAllowedTypes('timeout', 'int');
        $resolver->setAllowedTypes('retries', 'int');
        $resolver->setAllowedTypes('debug', 'bool');
        $resolver->setAllowedTypes('api_key', 'string');
    }
}

// Usage
$service = new MyService([
    'api_key' => 'secret-key',
    'timeout' => 60,
    'debug' => true,
]);
```

### Using ConfigurableTrait

```php
use Glueful\Config\ConfigurableInterface;
use Glueful\Config\ConfigurableTrait;

class DatabaseService implements ConfigurableInterface
{
    use ConfigurableTrait;
    
    public function __construct(array $config = [])
    {
        $this->resolveOptions($config);
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'host' => 'localhost',
            'port' => 3306,
            'charset' => 'utf8mb4',
            'timeout' => 30,
        ]);
        
        $resolver->setRequired(['database', 'username']);
        
        $resolver->setAllowedTypes('port', 'int');
        $resolver->setAllowedValues('port', function($value) {
            return $value >= 1 && $value <= 65535;
        });
    }
    
    public function connect(): void
    {
        $host = $this->getOption('host');
        $port = $this->getOption('port');
        // ... connection logic
    }
}
```

## Configuration Validation Features

### 1. Type Validation

```php
$resolver->setAllowedTypes('port', 'int');
$resolver->setAllowedTypes('host', 'string');
$resolver->setAllowedTypes('options', 'array');
$resolver->setAllowedTypes('callback', 'callable');
$resolver->setAllowedTypes('enabled', 'bool');

// Multiple types allowed
$resolver->setAllowedTypes('timeout', ['int', 'float']);
$resolver->setAllowedTypes('password', ['string', 'null']);
```

### 2. Value Constraints

```php
// Enum-style validation
$resolver->setAllowedValues('env', ['development', 'staging', 'production']);

// Custom validation functions
$resolver->setAllowedValues('port', function($value) {
    return $value >= 1024 && $value <= 65535;
});

$resolver->setAllowedValues('memory_limit', function($value) {
    return is_string($value) && preg_match('/^\d+[KMG]?$/', $value);
});
```

### 3. Required vs Optional

```php
// Required options (will throw exception if missing)
$resolver->setRequired(['database', 'username', 'password']);

// Optional with defaults
$resolver->setDefaults([
    'host' => 'localhost',
    'port' => 3306,
    'timeout' => 30,
]);
```

### 4. Value Normalization

```php
// Clean and normalize values
$resolver->setNormalizer('host', function($options, $value) {
    return trim(strtolower($value));
});

$resolver->setNormalizer('tags', function($options, $value) {
    if (is_string($value)) {
        $value = explode(',', $value);
    }
    return array_map('trim', $value);
});

// Cross-option validation
$resolver->setNormalizer('max_connections', function($options, $value) {
    if ($value < $options['min_connections']) {
        throw new \InvalidArgumentException(
            'max_connections must be >= min_connections'
        );
    }
    return $value;
});
```

## Real-World Examples

### 1. Queue Configuration (Implemented)

```php
class QueueConfigurable extends ConfigurableService
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'default' => 'database',
            'connections' => [],
            'failed' => [
                'driver' => 'database',
                'table' => 'queue_failed_jobs',
            ],
            'workers' => [
                'auto_scale' => false,
                'min_workers' => 1,
                'max_workers' => 10,
            ],
        ]);

        $resolver->setRequired(['default', 'connections']);
        
        // Validate that default connection exists
        $resolver->setNormalizer('default', function($options, $value) {
            if (!isset($options['connections'][$value])) {
                throw new \InvalidArgumentException(
                    "Default connection '{$value}' not found"
                );
            }
            return $value;
        });
    }
}
```

### 2. Notification Service (Implemented)

```php
class NotificationService implements ConfigurableInterface
{
    use ConfigurableTrait;
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'default_channels' => ['database'],
            'max_retry_attempts' => 3,
            'retry_delay_seconds' => 60,
            'rate_limit_per_minute' => 1000,
            'id_generator' => function() {
                return Utils::generateNanoID();
            },
        ]);
        
        // Validate channels
        $resolver->setNormalizer('default_channels', function($options, $value) {
            $validChannels = ['email', 'sms', 'database', 'slack', 'webhook'];
            foreach ($value as $channel) {
                if (!in_array($channel, $validChannels)) {
                    throw new \InvalidArgumentException(
                        "Invalid channel '{$channel}'"
                    );
                }
            }
            return array_unique($value);
        });
        
        // Test ID generator
        $resolver->setNormalizer('id_generator', function($options, $value) {
            $testId = $value();
            if (!is_string($testId) || empty($testId)) {
                throw new \InvalidArgumentException(
                    'id_generator must return non-empty string'
                );
            }
            return $value;
        });
    }
}
```

### 3. Connection Pool Configuration

```php
class ConfigurableConnectionPool extends ConnectionPool
{
    use ConfigurableTrait;
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'min_connections' => 2,
            'max_connections' => 10,
            'idle_timeout' => 300,
            'acquisition_timeout' => 30,
        ]);
        
        // Range validation
        $resolver->setAllowedValues('min_connections', function($value) {
            return $value >= 1 && $value <= 100;
        });
        
        // Cross-validation
        $resolver->setNormalizer('max_connections', function($options, $value) {
            if ($value < $options['min_connections']) {
                throw new \InvalidArgumentException(
                    'max_connections must be >= min_connections'
                );
            }
            return $value;
        });
    }
}
```

## Creating Configurable Services

### Option 1: Extend ConfigurableService

```php
use Glueful\Config\ConfigurableService;

class EmailService extends ConfigurableService
{
    private $mailer;
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'host' => 'localhost',
            'port' => 587,
            'encryption' => 'tls',
            'timeout' => 60,
        ]);
        
        $resolver->setRequired(['username', 'password']);
        
        $resolver->setAllowedValues('encryption', ['tls', 'ssl', null]);
        $resolver->setAllowedValues('port', function($value) {
            return in_array($value, [25, 465, 587, 2525]);
        });
    }
    
    public function send(string $to, string $subject, string $body): bool
    {
        $host = $this->getOption('host');
        $port = $this->getOption('port');
        // ... email sending logic
    }
}

// Usage
$emailService = new EmailService([
    'host' => 'smtp.example.com',
    'username' => 'user@example.com',
    'password' => 'secret',
    'port' => 587,
]);
```

### Option 2: Use ConfigurableTrait

```php
use Glueful\Config\ConfigurableInterface;
use Glueful\Config\ConfigurableTrait;

class CacheService implements ConfigurableInterface
{
    use ConfigurableTrait;
    
    public function __construct(array $config = [])
    {
        $this->resolveOptions($config);
        $this->initializeCache();
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'driver' => 'redis',
            'prefix' => 'cache_',
            'ttl' => 3600,
            'serializer' => 'json',
        ]);
        
        $resolver->setAllowedValues('driver', ['redis', 'file', 'memory']);
        $resolver->setAllowedValues('serializer', ['json', 'serialize', 'none']);
        
        $resolver->setAllowedTypes('ttl', 'int');
        $resolver->setAllowedValues('ttl', function($value) {
            return $value > 0 && $value <= 86400; // 1 second to 24 hours
        });
    }
    
    private function initializeCache(): void
    {
        $driver = $this->getOption('driver');
        $prefix = $this->getOption('prefix');
        // ... cache initialization
    }
}
```

## Advanced Configuration Patterns

### 1. Nested Configuration

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
        ],
        'cache' => [
            'driver' => 'redis',
            'ttl' => 3600,
        ],
    ]);
    
    // Validate nested arrays
    $resolver->setNormalizer('database', function($options, $value) {
        $dbResolver = new OptionsResolver();
        $dbResolver->setDefaults(['host' => 'localhost', 'port' => 3306]);
        $dbResolver->setRequired(['username', 'password']);
        $dbResolver->setAllowedTypes('port', 'int');
        
        return $dbResolver->resolve($value);
    });
}
```

### 2. Environment-Based Defaults

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
    
    $resolver->setDefaults([
        'debug' => $isDev,
        'timeout' => $isDev ? 0 : 30, // No timeout in dev
        'cache_enabled' => !$isDev,
        'log_level' => $isDev ? 'debug' : 'error',
    ]);
}
```

### 3. Conditional Validation

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'ssl_enabled' => false,
        'ssl_cert' => null,
        'ssl_key' => null,
    ]);
    
    // Require SSL files when SSL is enabled
    $resolver->setNormalizer('ssl_cert', function($options, $value) {
        if ($options['ssl_enabled'] && empty($value)) {
            throw new \InvalidArgumentException(
                'ssl_cert is required when ssl_enabled is true'
            );
        }
        return $value;
    });
}
```

### 4. Performance Scoring

```php
public function configureOptions(OptionsResolver $resolver): void
{
    // ... other configuration
    
    $resolver->setNormalizer('max_connections', function($options, $value) {
        if ($value > 50) {
            trigger_error(
                'High connection count may impact performance',
                E_USER_NOTICE
            );
        }
        return $value;
    });
}

public function getPerformanceScore(): int
{
    $config = $this->getOptions();
    $score = 100;
    
    if ($config['max_connections'] > 50) $score -= 20;
    if ($config['timeout'] > 60) $score -= 10;
    if (!$config['cache_enabled']) $score -= 15;
    
    return max(0, $score);
}
```

## Validation Examples

### 1. URL Validation

```php
$resolver->setNormalizer('api_url', function($options, $value) {
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException('Invalid URL format');
    }
    return rtrim($value, '/'); // Remove trailing slash
});
```

### 2. File Path Validation

```php
$resolver->setNormalizer('log_path', function($options, $value) {
    $dir = dirname($value);
    if (!is_dir($dir)) {
        throw new \InvalidArgumentException("Directory does not exist: {$dir}");
    }
    if (!is_writable($dir)) {
        throw new \InvalidArgumentException("Directory not writable: {$dir}");
    }
    return $value;
});
```

### 3. Memory Limit Validation

```php
$resolver->setNormalizer('memory_limit', function($options, $value) {
    if (!preg_match('/^(\d+)([KMG]?)$/i', $value, $matches)) {
        throw new \InvalidArgumentException('Invalid memory limit format');
    }
    
    $size = (int)$matches[1];
    $unit = strtoupper($matches[2] ?? '');
    
    $bytes = $size * match($unit) {
        'K' => 1024,
        'M' => 1024 * 1024,
        'G' => 1024 * 1024 * 1024,
        default => 1
    };
    
    if ($bytes > 2 * 1024 * 1024 * 1024) { // 2GB
        throw new \InvalidArgumentException('Memory limit too high');
    }
    
    return $value;
});
```

## Migrating Existing Services

### Before (Manual Validation)

```php
class OldService
{
    private array $config;
    
    public function __construct(array $config = [])
    {
        // Manual validation
        $this->config = array_merge([
            'timeout' => 30,
            'retries' => 3,
        ], $config);
        
        if (!isset($config['api_key'])) {
            throw new \InvalidArgumentException('api_key is required');
        }
        
        if (!is_int($this->config['timeout']) || $this->config['timeout'] <= 0) {
            throw new \InvalidArgumentException('timeout must be positive integer');
        }
        
        // ... more validation
    }
}
```

### After (OptionsResolver)

```php
class NewService extends ConfigurableService
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'timeout' => 30,
            'retries' => 3,
        ]);
        
        $resolver->setRequired(['api_key']);
        
        $resolver->setAllowedTypes('timeout', 'int');
        $resolver->setAllowedValues('timeout', function($value) {
            return $value > 0;
        });
        
        $resolver->setAllowedTypes('retries', 'int');
        $resolver->setAllowedValues('retries', function($value) {
            return $value >= 0 && $value <= 10;
        });
    }
}
```

## Error Handling

### Common Validation Errors

```php
try {
    $service = new MyService($config);
} catch (\Symfony\Component\OptionsResolver\Exception\InvalidOptionsException $e) {
    // Type mismatch or invalid value
    echo "Configuration error: " . $e->getMessage();
} catch (\Symfony\Component\OptionsResolver\Exception\MissingOptionsException $e) {
    // Required option missing
    echo "Missing required configuration: " . $e->getMessage();
} catch (\Symfony\Component\OptionsResolver\Exception\UndefinedOptionsException $e) {
    // Unknown option provided
    echo "Unknown configuration option: " . $e->getMessage();
}
```

### Custom Error Messages

```php
$resolver->setNormalizer('port', function($options, $value) {
    if (!is_int($value) || $value < 1 || $value > 65535) {
        throw new \InvalidArgumentException(
            "Port must be an integer between 1 and 65535, got: " . 
            (is_scalar($value) ? $value : gettype($value))
        );
    }
    return $value;
});
```

## Best Practices

1. **Always set sensible defaults**
2. **Use type validation for all options**
3. **Validate ranges and constraints**
4. **Provide clear error messages**
5. **Test your configuration validation**
6. **Document available options**
7. **Use cross-validation for related options**
8. **Consider environment-specific defaults**

## Benefits

- ✅ **Type Safety**: Automatic type checking
- ✅ **Validation**: Custom constraint validation
- ✅ **Defaults**: Sensible fallback values
- ✅ **Documentation**: Self-documenting configuration
- ✅ **Error Messages**: Clear validation errors
- ✅ **Normalization**: Automatic value cleanup
- ✅ **IDE Support**: Better autocomplete and hints
- ✅ **Consistency**: Standardized configuration across services

This integration makes Glueful services more robust, user-friendly, and maintainable!