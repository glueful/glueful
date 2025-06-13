<?php

namespace Glueful\Queue\Config;

/**
 * Configuration Validator for Queue System
 *
 * Validates queue configuration to ensure all required settings are present
 * and properly formatted. Provides detailed error reporting and suggestions
 * for fixing configuration issues.
 *
 * Features:
 * - Comprehensive validation rules
 * - Driver-specific validation
 * - Environment variable checking
 * - Configuration recommendations
 * - Security validation
 * - Performance optimization suggestions
 *
 * @package Glueful\Queue\Config
 */
class ConfigValidator
{
    /** @var array Validation errors */
    private array $errors = [];

    /** @var array Validation warnings */
    private array $warnings = [];

    /** @var array Configuration being validated */
    private array $config = [];

    /** @var array Known environment variables */
    private array $environmentVars = [];

    /**
     * Validate queue configuration
     *
     * @param array $config Configuration array
     * @return ValidationResult Validation result
     */
    public function validate(array $config): ValidationResult
    {
        $this->config = $config;
        $this->errors = [];
        $this->warnings = [];
        $this->environmentVars = $this->getEnvironmentVariables();

        // Core validation
        $this->validateStructure();
        $this->validateConnections();
        $this->validateDefaultConnection();
        $this->validateFailedJobsConfig();
        $this->validateBatchingConfig();
        $this->validateMonitoringConfig();
        $this->validateWorkerConfig();
        $this->validatePerformanceConfig();
        $this->validateSecurityConfig();
        $this->validatePluginConfig();

        // Cross-validation
        $this->validateCrossReferences();
        $this->validateEnvironmentVariables();
        $this->validateSecurity();
        $this->validatePerformanceSettings();

        return new ValidationResult(
            empty($this->errors),
            $this->errors,
            $this->warnings,
            $this->generateRecommendations()
        );
    }

    /**
     * Validate configuration structure
     *
     * @return void
     */
    private function validateStructure(): void
    {
        $requiredKeys = ['default', 'connections'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $this->config)) {
                $this->errors[] = "Missing required configuration key: '{$key}'";
            }
        }

        if (isset($this->config['connections']) && !is_array($this->config['connections'])) {
            $this->errors[] = "Configuration key 'connections' must be an array";
        }

        if (empty($this->config['connections'])) {
            $this->errors[] = "At least one queue connection must be configured";
        }
    }

    /**
     * Validate connection configurations
     *
     * @return void
     */
    private function validateConnections(): void
    {
        if (!isset($this->config['connections']) || !is_array($this->config['connections'])) {
            return;
        }

        foreach ($this->config['connections'] as $name => $connection) {
            $this->validateConnection($name, $connection);
        }
    }

    /**
     * Validate individual connection
     *
     * @param string $name Connection name
     * @param array $connection Connection configuration
     * @return void
     */
    private function validateConnection(string $name, array $connection): void
    {
        if (!isset($connection['driver'])) {
            $this->errors[] = "Connection '{$name}' is missing 'driver' configuration";
            return;
        }

        $driver = $connection['driver'];
        $method = "validate{$this->camelCase($driver)}Driver";

        if (method_exists($this, $method)) {
            $this->$method($name, $connection);
        } else {
            $this->warnings[] = "Unknown driver '{$driver}' for connection '{$name}'";
        }

        // Common validation for all drivers
        $this->validateCommonDriverSettings($name, $connection);
    }

    /**
     * Validate database driver configuration
     *
     * @param string $name Connection name
     * @param array $config Driver configuration
     * @return void
     */
    private function validateDatabaseDriver(string $name, array $config): void
    {
        $requiredFields = ['table'];
        $optionalFields = ['queue', 'retry_after', 'after_commit', 'failed_table'];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field])) {
                $this->errors[] = "Database driver '{$name}' missing required field: '{$field}'";
            }
        }

        if (isset($config['retry_after']) && (!is_numeric($config['retry_after']) || $config['retry_after'] < 0)) {
            $this->errors[] = "Database driver '{$name}' retry_after must be a positive number";
        }

        if (isset($config['after_commit']) && !is_bool($config['after_commit'])) {
            $this->errors[] = "Database driver '{$name}' after_commit must be a boolean";
        }
    }

    /**
     * Validate Redis driver configuration
     *
     * @param string $name Connection name
     * @param array $config Driver configuration
     * @return void
     */
    private function validateRedisDriver(string $name, array $config): void
    {
        $requiredFields = ['host', 'port'];
        $optionalFields = [
            'password', 'database', 'timeout', 'persistent', 'prefix',
            'queue', 'retry_after', 'block_for', 'job_expiration'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field])) {
                $this->errors[] = "Redis driver '{$name}' missing required field: '{$field}'";
            }
        }

        if (
            isset($config['port']) &&
            (!is_numeric($config['port']) || $config['port'] < 1 || $config['port'] > 65535)
        ) {
            $this->errors[] = "Redis driver '{$name}' port must be between 1 and 65535";
        }

        if (
            isset($config['database']) &&
            (!is_numeric($config['database']) || $config['database'] < 0 || $config['database'] > 15)
        ) {
            $this->errors[] = "Redis driver '{$name}' database must be between 0 and 15";
        }

        if (isset($config['timeout']) && (!is_numeric($config['timeout']) || $config['timeout'] < 0)) {
            $this->errors[] = "Redis driver '{$name}' timeout must be a positive number";
        }

        if (
            isset($config['job_expiration']) &&
            (!is_numeric($config['job_expiration']) || $config['job_expiration'] < 0)
        ) {
            $this->errors[] = "Redis driver '{$name}' job_expiration must be a positive number";
        }
    }


    /**
     * Validate sync driver configuration
     *
     * @param string $name Connection name
     * @param array $config Driver configuration
     * @return void
     */
    private function validateSyncDriver(string $name, array $config): void
    {
        // Sync driver doesn't need additional configuration
        if (count($config) > 1) {
            $this->warnings[] = "Sync driver '{$name}' has unnecessary configuration options";
        }
    }

    /**
     * Validate null driver configuration
     *
     * @param string $name Connection name
     * @param array $config Driver configuration
     * @return void
     */
    private function validateNullDriver(string $name, array $config): void
    {
        // Null driver doesn't need additional configuration
        if (count($config) > 1) {
            $this->warnings[] = "Null driver '{$name}' has unnecessary configuration options";
        }
    }

    /**
     * Validate common driver settings
     *
     * @param string $name Connection name
     * @param array $config Driver configuration
     * @return void
     */
    private function validateCommonDriverSettings(string $name, array $config): void
    {
        if (isset($config['queue']) && (!is_string($config['queue']) || empty($config['queue']))) {
            $this->errors[] = "Connection '{$name}' queue name must be a non-empty string";
        }

        if (isset($config['retry_after']) && (!is_numeric($config['retry_after']) || $config['retry_after'] < 0)) {
            $this->errors[] = "Connection '{$name}' retry_after must be a positive number";
        }
    }

    /**
     * Validate default connection
     *
     * @return void
     */
    private function validateDefaultConnection(): void
    {
        if (!isset($this->config['default'])) {
            return;
        }

        $defaultConnection = $this->config['default'];

        if (!isset($this->config['connections'][$defaultConnection])) {
            $this->errors[] = "Default connection '{$defaultConnection}' is not defined in connections";
        }
    }

    /**
     * Validate failed jobs configuration
     *
     * @return void
     */
    private function validateFailedJobsConfig(): void
    {
        if (!isset($this->config['failed'])) {
            $this->warnings[] = "Failed jobs configuration is not defined";
            return;
        }

        $failed = $this->config['failed'];

        if (isset($failed['max_retries']) && (!is_numeric($failed['max_retries']) || $failed['max_retries'] < 0)) {
            $this->errors[] = "Failed jobs max_retries must be a positive number";
        }

        if (
            isset($failed['retention_days']) &&
            (!is_numeric($failed['retention_days']) || $failed['retention_days'] < 0)
        ) {
            $this->errors[] = "Failed jobs retention_days must be a positive number";
        }
    }

    /**
     * Validate batching configuration
     *
     * @return void
     */
    private function validateBatchingConfig(): void
    {
        if (!isset($this->config['batching'])) {
            return;
        }

        $batching = $this->config['batching'];

        if (
            isset($batching['cleanup_after_days']) &&
            (!is_numeric($batching['cleanup_after_days']) || $batching['cleanup_after_days'] < 0)
        ) {
            $this->errors[] = "Batching cleanup_after_days must be a positive number";
        }
    }

    /**
     * Validate monitoring configuration
     *
     * @return void
     */
    private function validateMonitoringConfig(): void
    {
        if (!isset($this->config['monitoring'])) {
            return;
        }

        $monitoring = $this->config['monitoring'];

        if (isset($monitoring['enabled']) && !is_bool($monitoring['enabled'])) {
            $this->errors[] = "Monitoring enabled must be a boolean";
        }

        if (
            isset($monitoring['metrics_retention_days']) &&
            (!is_numeric($monitoring['metrics_retention_days']) || $monitoring['metrics_retention_days'] < 1)
        ) {
            $this->errors[] = "Monitoring metrics_retention_days must be a positive number";
        }

        if (isset($monitoring['alert_rules']) && is_array($monitoring['alert_rules'])) {
            foreach ($monitoring['alert_rules'] as $index => $rule) {
                $this->validateAlertRule($index, $rule);
            }
        }
    }

    /**
     * Validate alert rule configuration
     *
     * @param int $index Rule index
     * @param array $rule Rule configuration
     * @return void
     */
    private function validateAlertRule(int $index, array $rule): void
    {
        $requiredFields = ['name', 'condition', 'threshold'];

        foreach ($requiredFields as $field) {
            if (!isset($rule[$field])) {
                $this->errors[] = "Alert rule {$index} missing required field: '{$field}'";
            }
        }

        $validConditions = [
            'failure_rate_above',
            'queue_size_above',
            'active_workers_below',
            'avg_processing_time_above'
        ];

        if (isset($rule['condition']) && !in_array($rule['condition'], $validConditions)) {
            $this->errors[] = "Alert rule {$index} has invalid condition: '{$rule['condition']}'";
        }

        if (isset($rule['threshold']) && !is_numeric($rule['threshold'])) {
            $this->errors[] = "Alert rule {$index} threshold must be numeric";
        }

        $validSeverities = ['info', 'warning', 'error', 'critical'];

        if (isset($rule['severity']) && !in_array($rule['severity'], $validSeverities)) {
            $this->errors[] = "Alert rule {$index} has invalid severity: '{$rule['severity']}'";
        }
    }

    /**
     * Validate worker configuration
     *
     * @return void
     */
    private function validateWorkerConfig(): void
    {
        if (!isset($this->config['workers'])) {
            return;
        }

        $workers = $this->config['workers'];

        if (isset($workers['auto_scaling'])) {
            $this->validateAutoScalingConfig($workers['auto_scaling']);
        }

        if (isset($workers['resource_limits'])) {
            $this->validateResourceLimitsConfig($workers['resource_limits']);
        }

        if (isset($workers['performance'])) {
            $this->validateWorkerPerformanceConfig($workers['performance']);
        }
    }

    /**
     * Validate auto-scaling configuration
     *
     * @param array $config Auto-scaling configuration
     * @return void
     */
    private function validateAutoScalingConfig(array $config): void
    {
        $numericFields = ['min_workers', 'max_workers', 'scale_up_threshold', 'scale_down_threshold', 'scale_cooldown'];

        foreach ($numericFields as $field) {
            if (isset($config[$field]) && (!is_numeric($config[$field]) || $config[$field] < 0)) {
                $this->errors[] = "Auto-scaling {$field} must be a positive number";
            }
        }

        if (isset($config['min_workers'], $config['max_workers']) && $config['min_workers'] > $config['max_workers']) {
            $this->errors[] = "Auto-scaling min_workers cannot be greater than max_workers";
        }
    }

    /**
     * Validate resource limits configuration
     *
     * @param array $config Resource limits configuration
     * @return void
     */
    private function validateResourceLimitsConfig(array $config): void
    {
        if (isset($config['memory_limit'])) {
            if (!$this->isValidMemoryLimit($config['memory_limit'])) {
                $this->errors[] = "Invalid memory_limit format. Use format like '512M' or '1G'";
            }
        }

        $numericFields = ['time_limit', 'job_timeout', 'max_jobs_per_worker'];

        foreach ($numericFields as $field) {
            if (isset($config[$field]) && (!is_numeric($config[$field]) || $config[$field] < 0)) {
                $this->errors[] = "Resource limit {$field} must be a positive number";
            }
        }
    }

    /**
     * Validate worker performance configuration
     *
     * @param array $config Performance configuration
     * @return void
     */
    private function validateWorkerPerformanceConfig(array $config): void
    {
        $numericFields = ['sleep_seconds', 'max_tries', 'backoff_base', 'max_backoff'];

        foreach ($numericFields as $field) {
            if (isset($config[$field]) && (!is_numeric($config[$field]) || $config[$field] < 0)) {
                $this->errors[] = "Worker performance {$field} must be a positive number";
            }
        }

        $validBackoffStrategies = ['linear', 'exponential', 'fixed'];

        if (isset($config['backoff_strategy']) && !in_array($config['backoff_strategy'], $validBackoffStrategies)) {
            $this->errors[] = "Invalid backoff_strategy. Valid options: " . implode(', ', $validBackoffStrategies);
        }
    }

    /**
     * Validate performance configuration
     *
     * @return void
     */
    private function validatePerformanceConfig(): void
    {
        if (!isset($this->config['performance'])) {
            return;
        }

        // Performance validation logic would go here
        // This is a placeholder for future implementation
    }

    /**
     * Validate security configuration
     *
     * @return void
     */
    private function validateSecurityConfig(): void
    {
        if (!isset($this->config['security'])) {
            return;
        }

        // Security validation logic would go here
        // This is a placeholder for future implementation
    }

    /**
     * Validate plugin configuration
     *
     * @return void
     */
    private function validatePluginConfig(): void
    {
        if (!isset($this->config['plugins'])) {
            return;
        }

        $plugins = $this->config['plugins'];

        if (isset($plugins['discovery']['paths']) && is_array($plugins['discovery']['paths'])) {
            foreach ($plugins['discovery']['paths'] as $path) {
                if (!is_string($path)) {
                    $this->errors[] = "Plugin discovery path must be a string";
                } elseif (!is_dir($path)) {
                    $this->warnings[] = "Plugin discovery path does not exist: {$path}";
                }
            }
        }
    }

    /**
     * Validate cross-references between configuration sections
     *
     * @return void
     */
    private function validateCrossReferences(): void
    {
        // Validate that referenced databases exist, tables are configured correctly, etc.
        // This is a placeholder for future implementation
    }

    /**
     * Validate environment variables
     *
     * @return void
     */
    private function validateEnvironmentVariables(): void
    {
        // Check if environment variables referenced in config actually exist
        // This is a placeholder for future implementation
    }

    /**
     * Validate security settings
     *
     * @return void
     */
    private function validateSecurity(): void
    {
        // Security-specific validation
        // This is a placeholder for future implementation
    }

    /**
     * Validate performance settings
     *
     * @return void
     */
    private function validatePerformanceSettings(): void
    {
        // Performance-specific validation
        // This is a placeholder for future implementation
    }

    /**
     * Generate configuration recommendations
     *
     * @return array Recommendations
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];

        // Add performance recommendations
        if (
            !isset($this->config['performance']['connection_pooling']['enabled']) ||
            !$this->config['performance']['connection_pooling']['enabled']
        ) {
            $recommendations[] = "Consider enabling connection pooling for better performance";
        }

        // Add monitoring recommendations
        if (!isset($this->config['monitoring']['enabled']) || !$this->config['monitoring']['enabled']) {
            $recommendations[] = "Enable monitoring to track queue health and performance";
        }

        // Add security recommendations
        if (
            !isset($this->config['security']['encryption']['enabled']) ||
            !$this->config['security']['encryption']['enabled']
        ) {
            $recommendations[] = "Consider enabling payload encryption for sensitive data";
        }

        return $recommendations;
    }

    /**
     * Check if memory limit format is valid
     *
     * @param string $limit Memory limit string
     * @return bool True if valid
     */
    private function isValidMemoryLimit(string $limit): bool
    {
        return preg_match('/^\d+[KMG]?$/i', $limit) === 1;
    }

    /**
     * Convert string to camelCase
     *
     * @param string $string Input string
     * @return string CamelCase string
     */
    private function camelCase(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
    }

    /**
     * Get environment variables
     *
     * @return array Environment variables
     */
    private function getEnvironmentVariables(): array
    {
        // This would typically get from $_ENV or similar
        // For now, return empty array
        return [];
    }
}
