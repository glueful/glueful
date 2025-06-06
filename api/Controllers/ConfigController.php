<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Helpers\ConfigManager;
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Exceptions\SecurityException;
use Symfony\Component\HttpFoundation\Request;

class ConfigController
{
    public function __construct()
    {
        // Ensure ConfigManager is loaded
        // ConfigManager::load();
    }

    /**
     * Check if current user has permission
     *
     * @param Request $request
     * @param string $permission Permission to check
     * @param array $context Additional context
     * @return void
     * @throws SecurityException If permission check fails
     */
    private function checkPermission(Request $request, string $permission, array $context = []): void
    {
        $userUuid = $request->attributes->get('user_uuid');

        if (!$userUuid) {
            throw new SecurityException('User authentication required for this operation');
        }

        // Check if permission system is available
        if (!PermissionHelper::isAvailable()) {
            // Fallback: Allow any authenticated user when permission system unavailable
            error_log("FALLBACK: Permission system unavailable, allowing authenticated access for: {$permission}");
            return;
        }

        if (!PermissionHelper::hasPermission($userUuid, $permission, 'system', $context)) {
            throw new SecurityException("Insufficient permissions: {$permission} required");
        }
    }

    public function getConfigs(?Request $request = null): array
    {
        $request = $request ?? Request::createFromGlobals();

        // Check permission to view system configuration
        $this->checkPermission($request, PermissionStandards::PERMISSION_SYSTEM_ACCESS, [
            'action' => 'view_config',
            'endpoint' => '/config'
        ]);
        // Load config files directly from the config directory
        $configPath = dirname(__DIR__, 2) . '/config';
        $configFiles = glob($configPath . '/*.php');

        if ($configFiles === false) {
            return [];
        }

        $groupedConfig = [];
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

            $groupedConfig[] = [
                'name' => $name,
                'config' => $config,
            ];
        }

        return $groupedConfig;
    }

    public function getConfigByFile(string $filename): ?array
    {
        // Remove .php extension if present for consistent lookup
        $configName = str_replace('.php', '', $filename);

        // Build the full path to the config file
        $configPath = dirname(__DIR__, 2) . '/config';
        $filePath = $configPath . '/' . $configName . '.php';

        // Check if the file exists and is readable
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Load the config file directly
        $config = require $filePath;

        // Validate that config file returns an array
        if (!is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * Get nested config value using dot notation
     */
    public function getConfigValue(string $key, $default = null)
    {
        return ConfigManager::get($key, $default);
    }

    /**
     * Check if config exists
     */
    public function hasConfig(string $key): bool
    {
        return ConfigManager::has($key);
    }

    public function updateConfig(string $filename, array $data): bool
    {
        $configName = str_replace('.php', '', $filename);

        // Get existing config through ConfigManager
        $existingConfig = ConfigManager::get($configName, []);

        if (empty($existingConfig)) {
            return false;
        }

        // Merge and validate
        $newConfig = array_merge($existingConfig, $data);

        // Update in ConfigManager (runtime)
        ConfigManager::set($configName, $newConfig);

        // Persist to file
        $success = $this->persistConfigToFile($configName, $newConfig);

        if ($success) {
            $this->updateEnvVariables($data);
        }

        return $success;
    }

    /**
     * Update nested config value using dot notation
     */
    public function updateConfigValue(string $key, $value): bool
    {
        ConfigManager::set($key, $value);

        // Extract config file name from key (e.g., 'database.default' -> 'database')
        $configName = explode('.', $key)[0];
        $config = ConfigManager::get($configName);

        return $this->persistConfigToFile($configName, $config);
    }

    public function createConfig(string $filename, array $data): bool
    {
        $configName = str_replace('.php', '', $filename);

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
        $defaultConfig = $this->getDefaultConfig($configName);
        if (!$defaultConfig) {
            return false;
        }

        ConfigManager::set($configName, $defaultConfig);
        return $this->persistConfigToFile($configName, $defaultConfig);
    }
}
