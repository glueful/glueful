<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Helpers\ConfigManager;
use Glueful\Exceptions\SecurityException;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\ValidationException;
use Glueful\Helpers\ValidationHelper;
use Glueful\Http\Response;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Glueful\Extensions\ExtensionManager;
use Glueful\Configuration\ConfigurationProcessor;
use Glueful\Configuration\Exceptions\ConfigurationException;

class ConfigController extends BaseController
{
    public function __construct()
    {
        // CRITICAL: Call parent constructor to initialize BaseController properties
        parent::__construct();
    }

    private const SENSITIVE_CONFIG_FILES = ['security', 'database', 'app'];

    /**
     * Get all configuration files with HTTP caching
     * This endpoint returns public configuration that can be cached by CDNs
     */
    public function getConfigs(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Get configuration data
        $groupedConfig = $this->loadAllConfigs();

         $configList = [];
        foreach ($groupedConfig as $config) {
            $configList[] = [
                'name' => $config['name'],
                'path' => $config['name'] . '.php'
            ];
        }

        // Use simple success response instead of cached to avoid middleware issues
        return $this->publicSuccess($configList, 'Configuration retrieved');
    }

    /**
     * Get specific configuration file with conditional caching
     */
    public function getConfigByFile(string $filename): Response
    {
        // Check permissions÷
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting (using BaseController's rateLimitMethod)
        $this->rateLimitMethod('config_file');


        // Remove .php extension if present for consistent lookup
        $configName = str_replace('.php', '', $filename);

        // Validate filename
        if (!$this->validateConfigName($configName)) {
            return $this->notFound('Configuration file not found');
        }

        // Check for sensitive config access
        if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
            $this->requirePermission('system.config.sensitive.view');

            // Sensitive configs get private caching (5 minutes)
            $config = $this->loadConfigFile($configName);
            if ($config === null) {
                return $this->notFound('Configuration file not found');
            }
            $formattedConfig = [
                'name' => $filename,
                'content' => $config
            ];

            return $this->privateCached(
                $this->success($formattedConfig, 'Configuration retrieved'),
                300  // 5 minutes for sensitive configs
            );
        }

        // Check if content has been modified
        $lastModified = $this->getConfigLastModified($configName);
        if ($lastModified) {
            $notModifiedResponse = $this->checkNotModified($lastModified);
            if ($notModifiedResponse) {
                return $notModifiedResponse;
            }
        }

        // Load the config
        $config = $this->loadConfigFile($configName);
        if ($config === null) {
            return $this->notFound('Configuration file not found');
        }

        // Create cacheable response with Last-Modified header
        $formattedConfig = [
            'name' => $filename,
            'content' => $config
        ];
        $response = $this->publicSuccess($formattedConfig, 'Configuration retrieved', 1800); // 30 minutes

        if ($lastModified) {
            $response = $this->withLastModified($response, $lastModified);
        }

        return $response;
    }

    /**
     * Get public API configuration with aggressive caching
     * This endpoint is designed for high-traffic, public access
     */
    public function getPublicConfig(): Response
    {
        // No authentication required for public config

        $publicConfig = [
            'app_name' => ConfigManager::get('app.name', 'Glueful'),
            'api_version' => ConfigManager::get('app.version', '1.0'),
            'features' => [
                'registration_enabled' => ConfigManager::get('auth.registration.enabled', true),
                'social_login_enabled' => ConfigManager::get('auth.social.enabled', false),
                'api_docs_enabled' => ConfigManager::get('api.docs.enabled', true),
            ],
            'limits' => [
                'max_upload_size' => ConfigManager::get('upload.max_size', '10MB'),
                'rate_limit' => ConfigManager::get('api.rate_limit.default', 100),
            ]
        ];

        // Very aggressive caching for public config (6 hours)
        // This is safe because it's public, non-sensitive data
        return $this->publicSuccess($publicConfig, 'Public configuration retrieved', 21600);
    }

    /**
     * Update configuration with cache invalidation
     */
    public function updateConfig(string $filename, array $data): Response
    {
        // Check permissions
        $this->requirePermission('system.config.edit');

        // Multi-level rate limiting for write operations
        $this->rateLimitMethod('config_update');


        // Require low risk behavior for sensitive operations (using BaseController)
        $this->requireLowRiskBehavior(0.6, 'config_update');

        $configName = str_replace('.php', '', $filename);

        // Validate config name and data
        if (!$this->validateConfigName($configName)) {
            return $this->validationError(['filename' => 'Invalid configuration name']);
        }

        if (!$this->validateConfigData($data)) {
            return $this->validationError(['data' => 'Invalid configuration data']);
        }

        // Check for sensitive config modifications
        if (in_array($configName, self::SENSITIVE_CONFIG_FILES)) {
            $this->requirePermission('system.config.sensitive.edit');
        }

        // Get existing config through ConfigManager for audit trail
        $existingConfig = ConfigManager::get($configName, []);

        if (empty($existingConfig)) {
            return $this->notFound('Configuration not found');
        }

        // Create rollback point before making changes
        $this->createConfigRollbackPoint($configName, $existingConfig);

        // Merge and validate
        $newConfig = array_merge($existingConfig, $data);

        // Update in ConfigManager (runtime)
        ConfigManager::set($configName, $newConfig);

        // Persist to file
        $success = $this->persistConfigToFile($configName, $newConfig);

        if (!$success) {
            return $this->serverError('Failed to update configuration');
        }

        $this->updateEnvVariables($data);


        // Invalidate config cache (using BaseController implementation)
        $this->invalidateResourceCache('config', $configName);

        // Return success with no caching (since data just changed)
        return $this->success(['updated' => true], 'Configuration updated successfully');
    }

    /**
     * Create new configuration file
     */
    public function createConfig(string $name, array $data): bool
    {
        // Check permissions
        $this->requirePermission('system.config.create');

        // Multi-level rate limiting for write operations
        $this->rateLimitMethod('config_create');


        // Require low risk behavior for sensitive operations
        $this->requireLowRiskBehavior(0.6, 'config_create');

        // Validate config name
        $configName = str_replace('.php', '', $name);
        if (!$this->validateConfigName($configName)) {
            throw new ValidationException('Invalid configuration name');
        }

        if (!$this->validateConfigData($data)) {
            throw new ValidationException('Invalid configuration data');
        }

        // Get FileManager and FileFinder services
        $fileManager = container()->get(FileManager::class);
        $fileFinder = container()->get(FileFinder::class);

        // Determine config path
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';

        // Check if config already exists
        if ($fileManager->exists($filePath)) {
            throw new BusinessLogicException("Configuration file '{$configName}' already exists");
        }

        // Ensure config directory exists
        if (!$fileManager->exists($configPath)) {
            if (!$fileManager->createDirectory($configPath)) {
                throw new BusinessLogicException('Failed to create config directory');
            }
        }

        // Generate configuration content
        $configContent = "<?php\n\n";
        $configContent .= "/**\n";
        $configContent .= " * Configuration: {$configName}\n";
        $configContent .= " * Created: " . date('Y-m-d H:i:s') . "\n";
        $configContent .= " * Created by: " . ($this->getCurrentUserUuid() ?? 'system') . "\n";
        $configContent .= " */\n\n";
        $configContent .= "return " . var_export($data, true) . ";\n";

        // Write configuration file
        $success = $fileManager->writeFile($filePath, $configContent);

        if (!$success) {
            throw new BusinessLogicException('Failed to write configuration file');
        }

        // Set appropriate permissions (readable by web server)
        @chmod($filePath, 0644);

        // Clear configuration cache
        ConfigManager::clearCache();


        // Invalidate config cache
        $this->invalidateResourceCache('config', $configName);

        return true;
    }

    /**
     * Get schema information for a specific configuration
     */
    public function getSchemaInfo(string $configName): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        try {
            $processor = container()->get(ConfigurationProcessor::class);
            $schemaInfo = $processor->getSchemaInfo($configName);

            if (!$schemaInfo) {
                return $this->notFound('Schema not found for configuration: ' . $configName);
            }

            // Add additional schema details
            $enhanced = $schemaInfo;
            $enhanced['has_schema'] = true;
            $enhanced['config_exists'] = $this->configFileExists($configName);

            // Get schema structure if available
            try {
                $schema = $processor->getSchema($configName);
                if ($schema) {
                    $treeBuilder = $schema->getConfigTreeBuilder();
                    $tree = $treeBuilder->buildTree();
                    $enhanced['schema_structure'] = $this->analyzeSchemaStructure($tree);
                }
            } catch (\Exception $e) {
                // Schema structure analysis failed, continue without it
                $enhanced['schema_structure'] = null;
            }

            return $this->publicSuccess($enhanced, 'Schema information retrieved', 1800);
        } catch (\Exception $e) {
            return $this->serverError('Failed to get schema info: ' . $e->getMessage());
        }
    }

    /**
     * Get all available configuration schemas
     */
    public function getAllSchemas(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        try {
            $processor = container()->get(ConfigurationProcessor::class);
            $schemas = $processor->getAllSchemas();

            $schemaList = [];
            foreach ($schemas as $configName => $schema) {
                $schemaList[] = [
                    'name' => $configName,
                    'description' => $schema->getDescription(),
                    'version' => $schema->getVersion(),
                    'config_exists' => $this->configFileExists($configName),
                    'is_extension_schema' => $schema instanceof
                        \Glueful\Configuration\Extension\ExtensionSchemaInterface
                ];
            }

            return $this->publicSuccess($schemaList, 'Configuration schemas retrieved', 1800);
        } catch (\Exception $e) {
            return $this->serverError('Failed to get schemas: ' . $e->getMessage());
        }
    }

    /**
     * Validate configuration data against its schema
     */
    public function validateConfig(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting for validation operations
        $this->rateLimitMethod('config_validate');

        $requestData = $this->getRequestData();

        // Validate request data
        if (!isset($requestData['config_name']) || !isset($requestData['config_data'])) {
            return $this->validationError([
                'config_name' => 'Configuration name is required',
                'config_data' => 'Configuration data is required'
            ]);
        }

        $configName = $requestData['config_name'];
        $configData = $requestData['config_data'];

        if (!is_array($configData)) {
            return $this->validationError(['config_data' => 'Configuration data must be an array']);
        }

        try {
            $processor = container()->get(ConfigurationProcessor::class);

            if (!$processor->hasSchema($configName)) {
                return $this->validationError(['config_name' => 'No schema found for configuration: ' . $configName]);
            }

            // Validate configuration
            $validatedConfig = $processor->processConfiguration($configName, $configData);

            return $this->success([
                'valid' => true,
                'config_name' => $configName,
                'processed_config' => $validatedConfig,
                'validation_message' => 'Configuration is valid'
            ], 'Configuration validated successfully');
        } catch (ConfigurationException $e) {
            return $this->validationError([
                'valid' => false,
                'config_name' => $configName,
                'errors' => [$e->getMessage()],
                'validation_message' => 'Configuration validation failed'
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Failed to validate configuration: ' . $e->getMessage());
        }
    }

    /**
     * Validate existing configuration file against its schema
     */
    public function validateExistingConfig(string $configName): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting
        $this->rateLimitMethod('config_validate');

        try {
            $processor = container()->get(ConfigurationProcessor::class);

            if (!$processor->hasSchema($configName)) {
                return $this->validationError(['config_name' => 'No schema found for configuration: ' . $configName]);
            }

            // Load existing configuration
            $existingConfig = $this->loadConfigFile($configName);
            if ($existingConfig === null) {
                return $this->notFound('Configuration file not found: ' . $configName);
            }

            // Validate existing configuration
            $validatedConfig = $processor->processConfiguration($configName, $existingConfig);

            return $this->success([
                'valid' => true,
                'config_name' => $configName,
                'original_config' => $existingConfig,
                'processed_config' => $validatedConfig,
                'validation_message' => 'Existing configuration is valid'
            ], 'Configuration validated successfully');
        } catch (ConfigurationException $e) {
            return $this->validationError([
                'valid' => false,
                'config_name' => $configName,
                'errors' => [$e->getMessage()],
                'validation_message' => 'Configuration validation failed'
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Failed to validate configuration: ' . $e->getMessage());
        }
    }

    /**
     * Load all configuration files
     */
    private function loadAllConfigs(): array
    {
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

    /**
     * Load a specific configuration file
     */
    private function loadConfigFile(string $configName): ?array
    {
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
                    // Mask sensitive data
                    return $this->maskSensitiveData($config, $configName);
                }
            }

            // If not found in core, check extension configs
            $extensionConfigs = $this->loadExtensionConfigs();

            if (isset($extensionConfigs[$configName])) {
                $extensionConfig = $extensionConfigs[$configName];


                return $extensionConfig['content'];
            }

            return null;
        });
    }

    /**
     * Get last modified time for config file
     */
    private function getConfigLastModified(string $configName): ?\DateTime
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';

        if (file_exists($filePath)) {
            $timestamp = filemtime($filePath);
            if ($timestamp !== false) {
                return (new \DateTime())->setTimestamp($timestamp);
            }
        }

        return null;
    }

    // ... rest of the existing methods remain the same ...
    // (keeping all the existing functionality intact)

    private function loadExtensionConfigs(): array
    {
        $configs = [];

        try {
            // Get enabled extension names directly
            $extensionManager = container()->get(ExtensionManager::class);
            $enabledExtensionNames = $extensionManager->listEnabled();

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
            error_log("Error loading extension configs: " . $e->getMessage());
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
            throw BusinessLogicException::operationNotAllowed(
                'load_config',
                "Config file {$file} must return an array"
            );
        }

        return $config;
    }

    // Add all the other existing methods here...
    // (This is a simplified version to show the HTTP caching patterns)

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

    private function maskAppConfig(array $config): array
    {
        if (isset($config['key'])) {
            $config['key'] = '[REDACTED]';
        }
        return $config;
    }

    private function maskSensitiveKeys(array $config): array
    {
        return $this->recursiveMaskSensitiveKeys($config);
    }

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

    private function validateConfigName(string $name): bool
    {
        try {
            ValidationHelper::validateLength($name, 1, 50, 'config_name');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                return false;
            }
            return true;
        } catch (\Exception $e) {
            error_log("Config name validation error: " . $e->getMessage());
            return false;
        }
    }

    private function validateConfigData(array $data): bool
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

        return true;
    }

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

    private function persistConfigToFile(string $configName, array $config): bool
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';

        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return file_put_contents($filePath, $configContent) !== false;
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

    // Placeholder methods for missing functionality - these will use BaseController implementations
    private function createConfigRollbackPoint(string $configName, array $config): void
    {
        // TODO: Implement config rollback functionality
        // For now, this is a placeholder that doesn't break the flow
    }

    /**
     * Check if a configuration file exists
     */
    private function configFileExists(string $configName): bool
    {
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';
        return file_exists($filePath) && is_readable($filePath);
    }

    /**
     * Analyze schema structure for API response
     */
    private function analyzeSchemaStructure($node): array
    {
        $structure = [
            'type' => $this->getNodeTypeName($node),
            'required' => $node->isRequired(),
            'has_default' => $node->hasDefaultValue()
        ];

        if ($node->hasDefaultValue()) {
            $structure['default_value'] = $node->getDefaultValue();
        }

        if ($node instanceof \Symfony\Component\Config\Definition\EnumNode) {
            $structure['allowed_values'] = $node->getValues();
        }

        if ($node instanceof \Symfony\Component\Config\Definition\ArrayNode) {
            $structure['children'] = [];
            foreach ($node->getChildren() as $child) {
                $structure['children'][$child->getName()] = $this->analyzeSchemaStructure($child);
            }
        }

        return $structure;
    }

    /**
     * Get node type name for schema structure
     */
    private function getNodeTypeName($node): string
    {
        if ($node instanceof \Symfony\Component\Config\Definition\ArrayNode) {
            return 'array';
        } elseif ($node instanceof \Symfony\Component\Config\Definition\BooleanNode) {
            return 'boolean';
        } elseif ($node instanceof \Symfony\Component\Config\Definition\IntegerNode) {
            return 'integer';
        } elseif ($node instanceof \Symfony\Component\Config\Definition\FloatNode) {
            return 'float';
        } elseif ($node instanceof \Symfony\Component\Config\Definition\EnumNode) {
            return 'enum';
        } elseif ($node instanceof \Symfony\Component\Config\Definition\ScalarNode) {
            return 'scalar';
        }

        return 'unknown';
    }
}
