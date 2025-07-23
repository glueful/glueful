<?php

declare(strict_types=1);

namespace Glueful\Queue\Config;

/**
 * Modern Config Validator using OptionsResolver
 *
 * Replacement for the legacy ConfigValidator that uses Symfony OptionsResolver
 * for cleaner, more maintainable configuration validation.
 *
 * @package Glueful\Queue\Config
 */
class ConfigValidator
{
    /**
     * Validate queue configuration using OptionsResolver
     *
     * @param array $config Configuration array
     * @return ValidationResult Validation result
     */
    public function validate(array $config): ValidationResult
    {
        try {
            $queueConfig = new QueueConfigurable($config);

            // If we get here, configuration is valid
            return ValidationResult::success($this->generateSuccessRecommendations($queueConfig));
        } catch (\Symfony\Component\OptionsResolver\Exception\ExceptionInterface $e) {
            // OptionsResolver validation failed
            return ValidationResult::failure([$this->formatOptionsResolverError($e)]);
        } catch (\InvalidArgumentException $e) {
            // Custom validation failed
            return ValidationResult::failure([$e->getMessage()]);
        } catch (\Exception $e) {
            // Unexpected error
            return ValidationResult::failure([
                'Unexpected validation error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Format OptionsResolver errors into user-friendly messages
     */
    private function formatOptionsResolverError(
        \Symfony\Component\OptionsResolver\Exception\ExceptionInterface $e
    ): string {
        $message = $e->getMessage();

        // Common OptionsResolver error patterns and their user-friendly equivalents
        $patterns = [
            '/The option "([^"]+)" with value (.+) is expected to be of type "([^"]+)"/' =>
                'Configuration option "$1" must be of type $3, got $2',

            '/The option "([^"]+)" with value (.+) is invalid\. Accepted values are: (.+)/' =>
                'Configuration option "$1" has invalid value $2. Allowed values: $3',

            '/The required option "([^"]+)" is missing/' =>
                'Required configuration option "$1" is missing',

            '/The option "([^"]+)" does not exist/' =>
                'Unknown configuration option "$1"',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $message, $matches)) {
                $formatted = $replacement;
                for ($i = 1; $i < count($matches); $i++) {
                    $formatted = str_replace('$' . $i, $matches[$i], $formatted);
                }
                return $formatted;
            }
        }

        return $message;
    }

    /**
     * Generate recommendations for successful configuration
     */
    private function generateSuccessRecommendations(QueueConfigurable $config): array
    {
        $recommendations = [];

        // Check for performance optimizations
        $configArray = $config->toArray();
        $performance = $configArray['performance'] ?? [];
        if (!($performance['cache_enabled'] ?? true)) {
            $recommendations[] = 'Consider enabling cache for better performance';
        }

        // Check for monitoring
        $monitoring = $config->getMonitoringConfig();
        if (!$monitoring['enabled']) {
            $recommendations[] = 'Enable monitoring for better visibility into queue operations';
        }

        // Check for auto-scaling
        $workers = $config->getWorkersConfig();
        if (!$workers['auto_scale']) {
            $recommendations[] = 'Consider enabling auto-scaling for dynamic worker management';
        }

        // Check Redis configuration
        foreach ($config->getConnections() as $name => $connection) {
            if ($connection['driver'] === 'redis') {
                if ($connection['persistent'] === false) {
                    $recommendations[] = "Consider enabling persistent connections for Redis connection '{$name}'";
                }

                if ($connection['timeout'] < 5) {
                    $recommendations[] = "Redis timeout for connection '{$name}' is low, " .
                        "consider increasing for stability";
                }
            }
        }

        // Check worker limits
        if ($workers['max_workers'] > 20) {
            $recommendations[] = 'High max_workers setting may consume significant resources, ' .
                'monitor system performance';
        }

        return $recommendations;
    }

    /**
     * Get validation statistics
     */
    public function getValidationStats(array $config): array
    {
        try {
            $queueConfig = new QueueConfigurable($config);

            return [
                'connections_count' => count($queueConfig->getConnections()),
                'default_connection' => $queueConfig->getDefaultConnection(),
                'monitoring_enabled' => $queueConfig->getMonitoringConfig()['enabled'],
                'auto_scale_enabled' => $queueConfig->getWorkersConfig()['auto_scale'],
                'max_workers' => $queueConfig->getWorkersConfig()['max_workers'],
                'drivers_used' => array_unique(array_column($queueConfig->getConnections(), 'driver')),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
