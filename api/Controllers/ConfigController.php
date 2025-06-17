<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Helpers\ConfigManager;
use Glueful\Logging\AuditEvent;
use Glueful\Cache\CacheEngine;
use Glueful\Exceptions\SecurityException;
use InvalidArgumentException;

class ConfigController extends BaseController
{
    private const SENSITIVE_KEYS = [
        'jwt.secret',
        'jwt.private_key',
        'jwt.public_key',
        'database.password',
        'database.connections.*.password',
        'app.key',
        'mail.password',
        'services.*.secret',
        'services.*.key',
        'cache.redis.password',
        'session.encrypt_key'
    ];

    private const SENSITIVE_CONFIG_FILES = ['security', 'database', 'app'];

    private const CONFIG_ROLLBACK_RETENTION_DAYS = 30;
    private const MAX_ROLLBACK_VERSIONS = 10;
    public function getConfigs(): array
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Load config files directly from the config directory
        $configPath = dirname(__DIR__, 2) . '/config';
        $configFiles = glob($configPath . '/*.php');

        if ($configFiles === false) {
            return [];
        }

        $groupedConfig = [];

        // Load core configs
        foreach ($configFiles as $file) {
            // Skip if not a file or not readable
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $name = basename($file, '.php');

            // Load the config file
            $config = require $file;

            // Validate that config file returns an array
            if (!is_array($config)) {
                continue;
            }

            // Mask sensitive data based on user permissions
            $maskedConfig = $this->maskSensitiveData($config, $name);

            $groupedConfig[] = [
                'name' => $name,
                'config' => $maskedConfig,
                'source' => 'core'
            ];
        }

        // Load extension configs
        $extensionConfigs = $this->loadExtensionConfigs();
        foreach ($extensionConfigs as $extensionConfig) {
            $groupedConfig[] = [
                'name' => $extensionConfig['name'],
                'config' => $extensionConfig['content'],
                'source' => 'extension',
                'extension_version' => $extensionConfig['extension_version'] ?? null
            ];
        }

        return $groupedConfig;
    }


    private function loadExtensionConfigs(): array
    {
        $configs = [];

        try {
            // Get enabled extension names directly
            $enabledExtensionNames = \Glueful\Helpers\ExtensionsManager::getEnabledExtensions();

            foreach ($enabledExtensionNames as $extensionName) {
                // Check common config file locations in extensions directory
                $extensionPath = dirname(__DIR__, 2) . '/extensions/' . $extensionName;

                if (!is_dir($extensionPath)) {
                    continue;
                }

                // Check common config file locations
                $configPaths = [
                    $extensionPath . '/src/config.php',
                    $extensionPath . '/config.php',
                    $extensionPath . '/config/' . strtolower($extensionName) . '.php'
                ];

                foreach ($configPaths as $configFile) {
                    if (file_exists($configFile)) {
                        $config = $this->safeIncludeConfig($configFile);

                        // Mask sensitive data based on user permissions
                        $maskedConfig = $this->maskSensitiveData($config, $extensionName);

                        $configs[$extensionName] = [
                            'name' => $extensionName,
                            'source' => 'extension',
                            'content' => $maskedConfig,
                            'extension_version' => null, // Can be enhanced later if needed
                            'last_modified' => filemtime($configFile)
                        ];

                        break; // Only load first found config file
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire operation
        }

        return $configs;
    }

    private function safeIncludeConfig(string $file): array
    {
        // Basic security: ensure file is within allowed paths
        $realPath = realpath($file);
        $basePath = realpath(dirname(__DIR__, 2));

        if (!$realPath || !str_starts_with($realPath, $basePath)) {
            throw new SecurityException("Invalid config file path: {$file}");
        }

        $config = include $realPath;

        if (!is_array($config)) {
            throw new InvalidArgumentException("Config file {$file} must return an array");
        }

        return $config;
    }

    public function getConfigByFile(string $filename): ?array
    {

        // Check permissions
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting
        $this->applyMultiLevelRateLimit('config_file');

        // Behavior scoring for suspicious patterns
        $this->trackConfigAccessBehavior('file_access', ['filename' => $filename]);

        // Remove .php extension if present for consistent lookup
        $configName = str_replace('.php', '', $filename);

        // Validate filename
        if (!$this->validateConfigName($configName)) {
            return null;
        }

        // Check for sensitive config access
        if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
            $this->requirePermission('system.config.sensitive.view');
        }

        // Use permission-aware caching for config file access
        return $this->cacheByPermission("config_file_{$configName}", function () use ($configName) {
            // First check core config files
            $configPath = dirname(__DIR__, 2) . '/config';
            $filePath = $configPath . '/' . $configName . '.php';

            // Check if the core config file exists and is readable
            if (file_exists($filePath) && is_readable($filePath)) {
                // Load the config file directly
                $config = require $filePath;

                // Validate that config file returns an array
                if (is_array($config)) {
                    // Audit config file access
                    $this->auditLogger->audit(
                        AuditEvent::CATEGORY_SYSTEM,
                        'config_file_accessed',
                        AuditEvent::SEVERITY_INFO,
                        [
                            'user_uuid' => $this->getCurrentUserUuid(),
                            'config_file' => $configName,
                            'is_sensitive' => in_array($configName, self::SENSITIVE_CONFIG_FILES),
                            'source' => 'core'
                        ]
                    );

                    // Mask sensitive data
                    return $this->maskSensitiveData($config, $configName);
                }
            }

            // If not found in core, check extension configs
            $extensionConfigs = $this->loadExtensionConfigs();

            if (isset($extensionConfigs[$configName])) {
                $extensionConfig = $extensionConfigs[$configName];

                // Audit config file access
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_SYSTEM,
                    'config_file_accessed',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'config_file' => $configName,
                        'is_sensitive' => false, // Extension configs are not in sensitive list
                        'source' => 'extension'
                    ]
                );

                return $extensionConfig['content'];
            }

            return null;
        });
    }

    /**
     * Get nested config value using dot notation
     */
    public function getConfigValue(string $key, $default = null)
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Apply rate limiting
        $this->rateLimitResource('config', 'read', 100, 60);

        // Validate config key format
        if (!$this->validateConfigKey($key)) {
            return $default;
        }

        $value = ConfigManager::get($key, $default);

        // Mask sensitive values
        if ($this->isSensitiveKey($key)) {
            if (!$this->can('system.config.sensitive.view')) {
                return '[REDACTED]';
            }
        }

        return $value;
    }

    /**
     * Check if config exists
     */
    public function hasConfig(string $key): bool
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Apply rate limiting
        $this->rateLimitResource('config', 'read', 100, 60);

        // Validate config key format
        if (!$this->validateConfigKey($key)) {
            return false;
        }

        return ConfigManager::has($key);
    }

    public function updateConfig(string $filename, array $data): bool
    {
        // Check permissions
        $this->requirePermission('system.config.edit');

        // Multi-level rate limiting for write operations
        $this->applyMultiLevelRateLimit('config_update');

        // Behavior scoring for write operations
        $this->trackConfigModificationBehavior('update_config', ['filename' => $filename]);

        // Require low risk behavior for sensitive operations
        $this->requireLowRiskBehavior(0.6, 'config_update');

        $configName = str_replace('.php', '', $filename);

        // Validate config name and data
        if (!$this->validateConfigName($configName)) {
            return false;
        }

        if (!$this->validateConfigData($data, $configName)) {
            return false;
        }

        // Check for sensitive config modifications
        if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
            $this->requirePermission('system.config.sensitive.edit');
        }

        // Get existing config through ConfigManager for audit trail
        $existingConfig = ConfigManager::get($configName, []);

        if (empty($existingConfig)) {
            return false;
        }

        // Create rollback point before making changes
        $this->createConfigRollbackPoint($configName, $existingConfig);

        // Merge and validate
        $newConfig = array_merge($existingConfig, $data);

        // Update in ConfigManager (runtime)
        ConfigManager::set($configName, $newConfig);

        // Persist to file
        $success = $this->persistConfigToFile($configName, $newConfig);

        if ($success) {
            $this->updateEnvVariables($data);

            // Audit config update with before/after values
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'config_updated',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'config_file' => $configName,
                    'is_sensitive' => in_array($configName, self::SENSITIVE_CONFIG_FILES),
                    'changes' => array_keys($data),
                    'before' => $this->maskSensitiveData($existingConfig, $configName),
                    'after' => $this->maskSensitiveData($newConfig, $configName)
                ]
            );

            // Invalidate config cache
            $this->invalidateResourceCache('config', $configName);
        }

        return $success;
    }

    /**
     * Update nested config value using dot notation
     */
    public function updateConfigValue(string $key, $value): bool
    {
        // Check permissions
        $this->requirePermission('system.config.edit');

        // Multi-level rate limiting for write operations
        $this->applyMultiLevelRateLimit('config_update');

        // Behavior scoring for value updates
        $this->trackConfigModificationBehavior('update_value', ['key' => $key]);

        // Require low risk behavior for sensitive operations
        $this->requireLowRiskBehavior(0.6, 'config_value_update');

        // Validate config key and value
        if (!$this->validateConfigKey($key)) {
            return false;
        }

        if (!$this->validateConfigValue($key, $value)) {
            return false;
        }

        // Check for sensitive key modifications
        if ($this->isSensitiveKey($key)) {
            $this->requirePermission('system.config.sensitive.edit');
        }

        // Get old value for audit trail
        $oldValue = ConfigManager::get($key);

        ConfigManager::set($key, $value);

        // Extract config file name from key (e.g., 'database.default' -> 'database')
        $configName = explode('.', $key)[0];
        $config = ConfigManager::get($configName);

        $success = $this->persistConfigToFile($configName, $config);

        if ($success) {
            // Audit config value update with before/after values
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'config_value_updated',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'config_key' => $key,
                    'config_file' => $configName,
                    'before' => $this->isSensitiveKey($key) && !$this->can('system.config.sensitive.view')
                        ? '[REDACTED]' : $oldValue,
                    'after' => $this->isSensitiveKey($key) && !$this->can('system.config.sensitive.view')
                        ? '[REDACTED]' : $value
                ]
            );

            // Invalidate config cache
            $this->invalidateResourceCache('config', $configName);
        }

        return $success;
    }

    public function createConfig(string $filename, array $data): bool
    {
        // Check permissions
        $this->requirePermission('system.config.create');

        // Multi-level rate limiting for creation operations
        $this->applyMultiLevelRateLimit('config_create');

        // Behavior scoring for config creation
        $this->trackConfigModificationBehavior('create_config', ['filename' => $filename]);

        // Require low risk behavior for config creation
        $this->requireLowRiskBehavior(0.5, 'config_creation');

        $configName = str_replace('.php', '', $filename);

        // Validate config name and data
        if (!$this->validateConfigName($configName)) {
            return false;
        }

        if (!$this->validateConfigData($data, $configName)) {
            return false;
        }

        // Check if config already exists in ConfigManager
        if (ConfigManager::has($configName)) {
            return false;
        }

        // Validate config data structure
        $validationErrors = $this->validateConfigStructure($configName, $data);
        if (!empty($validationErrors)) {
            error_log('Config validation failed for ' . $configName . ': ' . implode(', ', $validationErrors));
            // Continue anyway for flexibility, but log the issues
        }

        // Add to ConfigManager
        ConfigManager::set($configName, $data);

        // Persist to file
        $success = $this->persistConfigToFile($configName, $data);

        if ($success) {
            $this->updateEnvVariables($data);

            // Audit config creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'config_created',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'config_file' => $configName,
                    'config_keys' => array_keys($data)
                ]
            );
        }

        return $success;
    }

    private function updateEnvVariables(array $data): void
    {
        $envPath = __DIR__ . '/../../.env';
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $updated = false;

        foreach ($data as $key => $value) {
            $envKey = $this->findEnvKeyForConfigValue($key);
            if ($envKey) {
                $lines = $this->updateEnvLine($lines, $envKey, $value);
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($envPath, implode("\n", $lines));
        }
    }

    private function findEnvKeyForConfigValue(string $key): string
    {
        // Map config keys to potential ENV keys
        // You might want to customize this mapping based on your needs
        return strtoupper(str_replace('.', '_', $key));
    }

    private function updateEnvLine(array $lines, string $key, $value): array
    {
        $newLine = $key . '=' . (is_string($value) ? '"' . $value . '"' : $value);

        foreach ($lines as $i => $line) {
            if (strpos($line, $key . '=') === 0) {
                $lines[$i] = $newLine;
                return $lines;
            }
        }

        $lines[] = $newLine;
        return $lines;
    }

    /**
     * Persist config data to file
     */
    private function persistConfigToFile(string $configName, array $config): bool
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';

        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return file_put_contents($filePath, $configContent) !== false;
    }

    /**
     * Validate config structure
     */
    private function validateConfigStructure(string $configName, array $config): array
    {
        $errors = [];

        switch ($configName) {
            case 'database':
                if (!isset($config['default'])) {
                    $errors[] = 'Missing default database connection';
                }
                break;

            case 'security':
                if (!isset($config['jwt']['secret'])) {
                    $errors[] = 'Missing JWT secret';
                }
                break;

            case 'app':
                if (!isset($config['key'])) {
                    $errors[] = 'Missing application key';
                }
                break;
        }

        return $errors;
    }

    /**
     * Get default config for a given config name
     */
    private function getDefaultConfig(string $configName): ?array
    {
        $defaultConfigs = [
            'app' => [
                'name' => 'Glueful',
                'env' => 'production',
                'debug' => false,
                'timezone' => 'UTC'
            ],
            'cache' => [
                'default' => 'file',
                'ttl' => 3600
            ]
        ];

        return $defaultConfigs[$configName] ?? null;
    }

    /**
     * Validate all configs or specific config
     */
    public function validateConfig(?string $configName = null): array
    {
        // Check permissions
        $this->requirePermission('system.config.validate');

        // Apply rate limiting
        $this->rateLimitResource('config', 'read', 30, 60);

        if ($configName) {
            return $this->validateConfigStructure($configName, ConfigManager::get($configName));
        }

        // Validate all configs
        $errors = [];
        foreach (ConfigManager::all() as $name => $config) {
            $configErrors = $this->validateConfigStructure($name, $config);
            if (!empty($configErrors)) {
                $errors[$name] = $configErrors;
            }
        }

        return $errors;
    }

    /**
     * Reset config to defaults
     */
    public function resetConfig(string $configName): bool
    {
        // Check permissions
        $this->requirePermission('system.config.reset');

        // Apply strict rate limiting for reset operations
        $this->rateLimitResource('config', 'write', 3, 300);

        // Require low risk behavior for config reset
        $this->requireLowRiskBehavior(0.4, 'config_reset');

        // Validate config name
        if (!$this->validateConfigName($configName)) {
            return false;
        }

        $defaultConfig = $this->getDefaultConfig($configName);
        if (!$defaultConfig) {
            return false;
        }

        ConfigManager::set($configName, $defaultConfig);
        $success = $this->persistConfigToFile($configName, $defaultConfig);

        if ($success) {
            // Audit config reset
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'config_reset',
                AuditEvent::SEVERITY_WARNING,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'config_file' => $configName
                ]
            );

            // Invalidate all config-related cache
            $this->invalidateResourceCache('config', $configName);
            $this->invalidateCache(['config', 'config_list', "config_file_{$configName}"]);
        }

        return $success;
    }

    /**
     * Mask sensitive data in configuration arrays
     */
    private function maskSensitiveData(array $config, string $configName): array
    {
        // If user has sensitive view permissions, return unmasked
        if ($this->can('system.config.sensitive.view')) {
            return $config;
        }

        $masked = $config;

        // Mask based on config file type
        switch ($configName) {
            case 'security':
                $masked = $this->maskSecurityConfig($masked);
                break;
            case 'database':
                $masked = $this->maskDatabaseConfig($masked);
                break;
            case 'app':
                $masked = $this->maskAppConfig($masked);
                break;
        }

        return $this->maskSensitiveKeys($masked);
    }

    /**
     * Mask security configuration sensitive values
     */
    private function maskSecurityConfig(array $config): array
    {
        if (isset($config['jwt']['secret'])) {
            $config['jwt']['secret'] = '[REDACTED]';
        }
        if (isset($config['jwt']['private_key'])) {
            $config['jwt']['private_key'] = '[REDACTED]';
        }
        if (isset($config['jwt']['public_key'])) {
            $config['jwt']['public_key'] = '[REDACTED]';
        }

        return $config;
    }

    /**
     * Mask database configuration sensitive values
     */
    private function maskDatabaseConfig(array $config): array
    {
        if (isset($config['password'])) {
            $config['password'] = '[REDACTED]';
        }

        if (isset($config['connections']) && is_array($config['connections'])) {
            foreach ($config['connections'] as $name => $connection) {
                if (isset($connection['password'])) {
                    $config['connections'][$name]['password'] = '[REDACTED]';
                }
            }
        }

        return $config;
    }

    /**
     * Mask app configuration sensitive values
     */
    private function maskAppConfig(array $config): array
    {
        if (isset($config['key'])) {
            $config['key'] = '[REDACTED]';
        }

        return $config;
    }

    /**
     * Mask sensitive keys using pattern matching
     */
    private function maskSensitiveKeys(array $config): array
    {
        return $this->recursiveMaskSensitiveKeys($config);
    }

    /**
     * Recursively mask sensitive keys
     */
    private function recursiveMaskSensitiveKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->recursiveMaskSensitiveKeys($value);
            } elseif ($this->isSensitiveKeyName($key)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * Check if a key name is sensitive
     */
    private function isSensitiveKeyName(string $key): bool
    {
        $sensitivePatterns = [
            'password', 'secret', 'key', 'token', 'private_key',
            'public_key', 'api_key', 'auth_key', 'encryption_key'
        ];

        $lowerKey = strtolower($key);

        foreach ($sensitivePatterns as $pattern) {
            if (strpos($lowerKey, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a config key is sensitive
     */
    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (fnmatch($sensitiveKey, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate config name format
     */
    private function validateConfigName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
    }

    /**
     * Validate config key format
     */
    private function validateConfigKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_.-]+$/', $key) === 1;
    }

    /**
     * Validate config data structure
     */
    private function validateConfigData(array $data, string $configName): bool
    {
        // Basic validation
        if (empty($data) || !is_array($data)) {
            return false;
        }

        // Check max depth
        if ($this->getArrayDepth($data) > 5) {
            return false;
        }

        // Check max keys
        if (count($data, COUNT_RECURSIVE) > 100) {
            return false;
        }

        // Config-specific validation
        switch ($configName) {
            case 'database':
                return $this->validateDatabaseConfig($data);
            case 'security':
                return $this->validateSecurityConfig($data);
            case 'app':
                return $this->validateAppConfig($data);
        }

        return true;
    }

    /**
     * Validate config value
     */
    private function validateConfigValue(string $key, $value): bool
    {
        // Basic validation - ensure value is not null for required keys
        if ($value === null && $this->isRequiredKey($key)) {
            return false;
        }

        // Key-specific validation
        if (strpos($key, 'password') !== false) {
            return is_string($value) && strlen($value) >= 8;
        } elseif (strpos($key, 'secret') !== false || strpos($key, 'key') !== false) {
            return is_string($value) && strlen($value) >= 16;
        } elseif (strpos($key, 'port') !== false) {
            return is_int($value) && $value >= 1 && $value <= 65535;
        } elseif (strpos($key, 'timeout') !== false) {
            return is_int($value) && $value >= 1;
        }

        return true;
    }

    /**
     * Validate database configuration
     */
    private function validateDatabaseConfig(array $config): bool
    {
        // Check for required 'default' key
        if (!isset($config['default'])) {
            return false;
        }

        // Validate connections if they exist
        if (isset($config['connections'])) {
            foreach ($config['connections'] as $connection) {
                if (!isset($connection['driver']) || !isset($connection['host'])) {
                    return false;
                }

                $allowedDrivers = ['mysql', 'postgresql', 'sqlite'];
                if (!in_array($connection['driver'], $allowedDrivers)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate security configuration
     */
    private function validateSecurityConfig(array $config): bool
    {
        // Check JWT secret if JWT config exists
        if (isset($config['jwt']['secret'])) {
            if (!is_string($config['jwt']['secret']) || strlen($config['jwt']['secret']) < 32) {
                return false;
            }
        }

        // Check rate limiter defaults if they exist
        if (isset($config['rate_limiter']['defaults'])) {
            if (!is_array($config['rate_limiter']['defaults'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate app configuration
     */
    private function validateAppConfig(array $config): bool
    {
        // Check required keys
        if (!isset($config['name']) || !isset($config['env'])) {
            return false;
        }

        // Validate app key length
        if (isset($config['key'])) {
            if (!is_string($config['key']) || strlen($config['key']) < 32) {
                return false;
            }
        }

        // Validate environment
        $allowedEnvs = ['development', 'staging', 'production'];
        if (!in_array($config['env'], $allowedEnvs)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a key is required
     */
    private function isRequiredKey(string $key): bool
    {
        $requiredKeys = [
            'app.name',
            'app.env',
            'app.key',
            'database.default',
            'security.jwt.secret'
        ];

        return in_array($key, $requiredKeys);
    }

    /**
     * Get array depth
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }

        return $maxDepth;
    }

    /**
     * Apply multi-level rate limiting
     */
    private function applyMultiLevelRateLimit(string $operation): void
    {
        $limits = [
            'ip' => [
                'attempts' => match ($operation) {
                    'config_list' => 200,
                    'config_file' => 100,
                    'config_update', 'config_create' => 20,
                    default => 60
                },
                'window' => 60
            ],
            'user' => [
                'attempts' => match ($operation) {
                    'config_list' => $this->isAdmin() ? 1000 : 500,
                    'config_file' => $this->isAdmin() ? 500 : 200,
                    'config_update', 'config_create' => $this->isAdmin() ? 100 : 30,
                    default => $this->isAdmin() ? 200 : 100
                },
                'window' => 60
            ],
            'endpoint' => [
                'attempts' => match ($operation) {
                    'config_list' => 300,
                    'config_file' => 150,
                    'config_update', 'config_create' => 40,
                    default => 80
                },
                'window' => 60,
                'adaptive' => true
            ]
        ];

        $this->multiLevelRateLimit($operation, $limits);
    }

    /**
     * Track config access behavior patterns
     */
    private function trackConfigAccessBehavior(string $action, array $context = []): void
    {
        $behaviorKey = sprintf(
            'config_behavior:%s:%s',
            $this->getCurrentUserUuid() ?? $this->request->getClientIp(),
            date('Y-m-d-H')
        );

        $behaviorData = [
            'timestamp' => time(),
            'action' => $action,
            'user_agent' => $this->request->headers->get('User-Agent'),
            'ip' => $this->request->getClientIp(),
            'context' => $context
        ];

        // Store behavior data
        $existingBehavior = json_decode(CacheEngine::get($behaviorKey) ?? '[]', true);
        $existingBehavior[] = $behaviorData;

        // Keep only last 100 actions
        if (count($existingBehavior) > 100) {
            $existingBehavior = array_slice($existingBehavior, -100);
        }

        CacheEngine::set($behaviorKey, json_encode($existingBehavior), 3600);

        // Analyze for suspicious patterns
        $this->analyzeBehaviorPatterns($existingBehavior, $action);
    }

    /**
     * Track config modification behavior
     */
    private function trackConfigModificationBehavior(string $action, array $context = []): void
    {
        $this->trackConfigAccessBehavior($action, $context);

        // Additional tracking for modifications
        $modificationKey = sprintf(
            'config_modifications:%s:%s',
            $this->getCurrentUserUuid() ?? 'anonymous',
            date('Y-m-d')
        );

        $modificationData = [
            'timestamp' => time(),
            'action' => $action,
            'context' => $context,
            'risk_score' => $this->calculateModificationRiskScore($action, $context)
        ];

        $existingMods = json_decode(CacheEngine::get($modificationKey) ?? '[]', true);
        $existingMods[] = $modificationData;

        CacheEngine::set($modificationKey, json_encode($existingMods), 86400);

        // Check for high-risk modification patterns
        $this->checkHighRiskModificationPatterns($existingMods);
    }

    /**
     * Analyze behavior patterns for suspicious activity
     */
    private function analyzeBehaviorPatterns(array $behavior, string $currentAction): void
    {
        $recentActions = array_filter($behavior, fn($b) => $b['timestamp'] > (time() - 300)); // Last 5 minutes
        $actionCounts = array_count_values(array_column($recentActions, 'action'));

        $suspiciousPatterns = [
            // Rapid successive access to sensitive configs
            'rapid_sensitive_access' => isset($actionCounts['file_access']) && $actionCounts['file_access'] > 10,

            // Mass config enumeration
            'mass_enumeration' => isset($actionCounts['list_all']) && $actionCounts['list_all'] > 5,

            // Mixed access patterns (both read and write in short period)
            'mixed_patterns' => count($actionCounts) > 3 && array_sum($actionCounts) > 15,

            // Unusual user agent patterns
            'unusual_ua' => $this->detectUnusualUserAgent($recentActions)
        ];

        foreach ($suspiciousPatterns as $pattern => $detected) {
            if ($detected) {
                $this->auditLogger->audit(
                    'security',
                    'suspicious_config_behavior',
                    AuditEvent::SEVERITY_WARNING,
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'pattern' => $pattern,
                        'action_counts' => $actionCounts,
                        'current_action' => $currentAction,
                        'ip' => $this->request->getClientIp()
                    ]
                );

                // Increase rate limiting for suspicious users
                $this->applyPenaltyRateLimit($pattern);
            }
        }
    }

    /**
     * Calculate modification risk score
     */
    private function calculateModificationRiskScore(string $action, array $context): float
    {
        $baseScore = match ($action) {
            'update_config' => 0.5,
            'create_config' => 0.7,
            'delete_config' => 0.9,
            default => 0.3
        };

        // Increase score for sensitive configs
        if (isset($context['filename'])) {
            $configName = str_replace('.php', '', $context['filename']);
            if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
                $baseScore += 0.3;
            }
        }

        // Time-based risk (higher risk during off-hours)
        $hour = (int)date('H');
        if ($hour < 6 || $hour > 22) {
            $baseScore += 0.2;
        }

        // User-based risk
        if (!$this->isAdmin()) {
            $baseScore += 0.1;
        }

        return min(1.0, $baseScore);
    }

    /**
     * Check for high-risk modification patterns
     */
    private function checkHighRiskModificationPatterns(array $modifications): void
    {
        $recentMods = array_filter($modifications, fn($m) => $m['timestamp'] > (time() - 3600)); // Last hour
        $avgRiskScore = array_sum(array_column($recentMods, 'risk_score')) / max(1, count($recentMods));

        if (count($recentMods) > 10 || $avgRiskScore > 0.7) {
            $this->auditLogger->audit(
                'security',
                'high_risk_config_modification_pattern',
                AuditEvent::SEVERITY_ERROR,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'modification_count' => count($recentMods),
                    'average_risk_score' => $avgRiskScore,
                    'ip' => $this->request->getClientIp()
                ]
            );

            // Apply strict rate limiting
            $this->requireLowRiskBehavior(0.3, 'high_risk_pattern_detected');
        }
    }

    /**
     * Detect unusual user agent patterns
     */
    private function detectUnusualUserAgent(array $recentActions): bool
    {
        $userAgents = array_unique(array_column($recentActions, 'user_agent'));

        // More than 3 different user agents in 5 minutes is suspicious
        if (count($userAgents) > 3) {
            return true;
        }

        // Check for known bot patterns
        foreach ($userAgents as $ua) {
            if (preg_match('/bot|crawler|spider|scraper/i', $ua)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply penalty rate limiting for suspicious behavior
     */
    private function applyPenaltyRateLimit(string $pattern): void
    {
        $penaltyLimits = [
            'rapid_sensitive_access' => ['attempts' => 5, 'window' => 300],
            'mass_enumeration' => ['attempts' => 3, 'window' => 600],
            'mixed_patterns' => ['attempts' => 10, 'window' => 300],
            'unusual_ua' => ['attempts' => 2, 'window' => 900]
        ];

        if (isset($penaltyLimits[$pattern])) {
            $limits = $penaltyLimits[$pattern];
            $this->rateLimit("penalty_{$pattern}", $limits['attempts'], $limits['window'], false);
        }
    }

    /**
     * Create config rollback point
     */
    private function createConfigRollbackPoint(string $configName, array $config): void
    {
        $rollbackKey = sprintf('config_rollback:%s', $configName);
        $existingRollbacks = json_decode(CacheEngine::get($rollbackKey) ?? '[]', true);

        $rollbackPoint = [
            'timestamp' => time(),
            'config' => $config,
            'user_uuid' => $this->getCurrentUserUuid(),
            'ip' => $this->request->getClientIp(),
            'user_agent' => $this->request->headers->get('User-Agent'),
            'version' => count($existingRollbacks) + 1
        ];

        $existingRollbacks[] = $rollbackPoint;

        // Keep only the last N versions
        if (count($existingRollbacks) > self::MAX_ROLLBACK_VERSIONS) {
            $existingRollbacks = array_slice($existingRollbacks, -self::MAX_ROLLBACK_VERSIONS);
        }

        // Store rollback data
        $ttl = self::CONFIG_ROLLBACK_RETENTION_DAYS * 86400; // Convert days to seconds
        CacheEngine::set($rollbackKey, json_encode($existingRollbacks), $ttl);

        // Also persist to storage for important configs
        if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
            $this->persistRollbackPoint($configName, $rollbackPoint);
        }
    }

    /**
     * Persist rollback point to storage
     */
    private function persistRollbackPoint(string $configName, array $rollbackPoint): void
    {
        $rollbackDir = dirname(__DIR__, 2) . '/storage/config_rollbacks';
        if (!is_dir($rollbackDir)) {
            mkdir($rollbackDir, 0755, true);
        }

        $filename = sprintf(
            '%s/%s_%s_%d.json',
            $rollbackDir,
            $configName,
            date('Y-m-d_H-i-s'),
            $rollbackPoint['version']
        );

        file_put_contents($filename, json_encode($rollbackPoint, JSON_PRETTY_PRINT));
    }

    /**
     * Rollback config to a previous version
     */
    public function rollbackConfig(string $configName, int $version): bool
    {
        // Check permissions
        $this->requirePermission('system.config.reset');

        // Multi-level rate limiting for rollback operations
        $this->applyMultiLevelRateLimit('config_rollback');

        // Require very low risk behavior for rollbacks
        $this->requireLowRiskBehavior(0.3, 'config_rollback');

        // Validate config name
        if (!$this->validateConfigName($configName)) {
            return false;
        }

        $rollbackKey = sprintf('config_rollback:%s', $configName);
        $rollbacks = json_decode(CacheEngine::get($rollbackKey) ?? '[]', true);

        // Find the specified version
        $targetRollback = null;
        foreach ($rollbacks as $rollback) {
            if ($rollback['version'] === $version) {
                $targetRollback = $rollback;
                break;
            }
        }

        if (!$targetRollback) {
            return false;
        }

        // Create a rollback point for current state before rolling back
        $currentConfig = ConfigManager::get($configName);
        $this->createConfigRollbackPoint($configName, $currentConfig);

        // Apply the rollback
        ConfigManager::set($configName, $targetRollback['config']);
        $success = $this->persistConfigToFile($configName, $targetRollback['config']);

        if ($success) {
            // Audit the rollback
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'config_rollback',
                AuditEvent::SEVERITY_WARNING,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'config_file' => $configName,
                    'rollback_version' => $version,
                    'rollback_timestamp' => $targetRollback['timestamp'],
                    'original_user' => $targetRollback['user_uuid']
                ]
            );

            // Invalidate all config-related cache
            $this->invalidateResourceCache('config', $configName);
            $this->invalidateCache(['config', 'config_list', "config_file_{$configName}"]);
        }

        return $success;
    }

    /**
     * List available rollback versions for a config
     */
    public function listConfigRollbacks(string $configName): array
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Validate config name
        if (!$this->validateConfigName($configName)) {
            return [];
        }

        $rollbackKey = sprintf('config_rollback:%s', $configName);
        $rollbacks = json_decode(CacheEngine::get($rollbackKey) ?? '[]', true);

        // Return sanitized rollback list
        return array_map(function ($rollback) {
            return [
                'version' => $rollback['version'],
                'timestamp' => $rollback['timestamp'],
                'created_by' => $rollback['user_uuid'],
                'created_at' => date('Y-m-d H:i:s', $rollback['timestamp']),
                'has_config' => !empty($rollback['config'])
            ];
        }, array_reverse($rollbacks)); // Most recent first
    }

    /**
     * Clean up old rollback data
     */
    private function cleanupOldRollbacks(): void
    {
        $cutoffTime = time() - (self::CONFIG_ROLLBACK_RETENTION_DAYS * 86400);

        // Clean up cache-based rollbacks (happens automatically with TTL)

        // Clean up persisted rollbacks
        $rollbackDir = dirname(__DIR__, 2) . '/storage/config_rollbacks';
        if (is_dir($rollbackDir)) {
            $files = glob($rollbackDir . '/*.json');
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
        }
    }
}
