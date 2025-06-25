<?php

declare(strict_types=1);

namespace Glueful\Queue\Config;

use Glueful\Config\ConfigurableService;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Queue Configuration with OptionsResolver
 *
 * Replaces the large ConfigValidator class with a cleaner, more maintainable
 * OptionsResolver-based configuration validation system.
 *
 * @package Glueful\Queue\Config
 */
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
            'batching' => [
                'driver' => 'database',
                'table' => 'queue_batches',
            ],
            'monitoring' => [
                'enabled' => true,
                'heartbeat_interval' => 30,
                'metrics_retention' => 86400, // 24 hours
            ],
            'workers' => [
                'auto_scale' => false,
                'min_workers' => 1,
                'max_workers' => 10,
                'process' => [
                    'memory_limit' => '256M',
                    'timeout' => 3600,
                    'restart_threshold' => 1000,
                ],
            ],
            'performance' => [
                'bulk_operations' => true,
                'chunk_size' => 1000,
                'cache_enabled' => true,
                'cache_ttl' => 300,
            ],
            'security' => [
                'encryption' => false,
                'rate_limiting' => false,
                'max_jobs_per_minute' => 60,
            ],
        ]);

        $resolver->setRequired(['default', 'connections']);

        $resolver->setAllowedTypes('default', 'string');
        $resolver->setAllowedTypes('connections', 'array');
        $resolver->setAllowedTypes('failed', 'array');
        $resolver->setAllowedTypes('batching', 'array');
        $resolver->setAllowedTypes('monitoring', 'array');
        $resolver->setAllowedTypes('workers', 'array');
        $resolver->setAllowedTypes('performance', 'array');
        $resolver->setAllowedTypes('security', 'array');

        // Validate that default connection exists in connections
        $resolver->setNormalizer('default', function ($options, $value) {
            if (!isset($options['connections'][$value])) {
                throw new \InvalidArgumentException(
                    "Default connection '{$value}' not found in connections array"
                );
            }
            return $value;
        });

        // Validate each connection configuration
        $resolver->setNormalizer('connections', function ($options, $connections) {
            foreach ($connections as $name => $connection) {
                $this->validateConnection($name, $connection);
            }
            return $connections;
        });

        // Validate monitoring configuration
        $resolver->setNormalizer('monitoring', function ($options, $monitoring) {
            $monitoringResolver = new OptionsResolver();
            $this->configureMonitoringOptions($monitoringResolver);
            return $monitoringResolver->resolve($monitoring);
        });

        // Validate worker configuration
        $resolver->setNormalizer('workers', function ($options, $workers) {
            $workersResolver = new OptionsResolver();
            $this->configureWorkersOptions($workersResolver);
            return $workersResolver->resolve($workers);
        });
    }

    /**
     * Validate individual connection configuration
     */
    private function validateConnection(string $name, array $connection): void
    {
        $resolver = new OptionsResolver();

        $resolver->setRequired(['driver']);
        $resolver->setAllowedTypes('driver', 'string');
        $resolver->setAllowedValues('driver', ['database', 'redis', 'sync', 'null']);

        // Common options for all drivers
        $resolver->setDefaults([
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);

        $resolver->setAllowedTypes('queue', 'string');
        $resolver->setAllowedTypes('retry_after', 'int');
        $resolver->setAllowedTypes('after_commit', 'bool');

        // Driver-specific validation
        $resolver->setNormalizer('driver', function ($options, $value) use ($name, $resolver) {
            match ($value) {
                'database' => $this->configureDatabaseConnection($resolver),
                'redis' => $this->configureRedisConnection($resolver),
                'sync', 'null' => null, // No additional config needed
                default => throw new \InvalidArgumentException(
                    "Unknown driver '{$value}' for connection '{$name}'"
                )
            };
            return $value;
        });

        try {
            $resolver->resolve($connection);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid configuration for connection '{$name}': " . $e->getMessage()
            );
        }
    }

    /**
     * Configure database connection options
     */
    private function configureDatabaseConnection(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'table' => 'queue_jobs',
            'failed_table' => 'queue_failed_jobs',
        ]);

        $resolver->setAllowedTypes('table', 'string');
        $resolver->setAllowedTypes('failed_table', 'string');
    }

    /**
     * Configure Redis connection options
     */
    private function configureRedisConnection(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 5,
            'persistent' => false,
            'prefix' => 'glueful:queue:',
            'block_for' => null,
        ]);

        $resolver->setAllowedTypes('host', 'string');
        $resolver->setAllowedTypes('port', 'int');
        $resolver->setAllowedTypes('password', ['string', 'null']);
        $resolver->setAllowedTypes('database', 'int');
        $resolver->setAllowedTypes('timeout', 'int');
        $resolver->setAllowedTypes('persistent', 'bool');
        $resolver->setAllowedTypes('prefix', 'string');
        $resolver->setAllowedTypes('block_for', ['int', 'null']);

        // Validate port range
        $resolver->setAllowedValues('port', function ($value) {
            return $value >= 1 && $value <= 65535;
        });

        // Validate database number
        $resolver->setAllowedValues('database', function ($value) {
            return $value >= 0 && $value <= 15;
        });
    }

    /**
     * Configure monitoring options
     */
    private function configureMonitoringOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'enabled' => true,
            'heartbeat_interval' => 30,
            'metrics_retention' => 86400,
        ]);

        $resolver->setAllowedTypes('enabled', 'bool');
        $resolver->setAllowedTypes('heartbeat_interval', 'int');
        $resolver->setAllowedTypes('metrics_retention', 'int');

        $resolver->setAllowedValues('heartbeat_interval', function ($value) {
            return $value >= 5 && $value <= 300; // 5 seconds to 5 minutes
        });

        $resolver->setAllowedValues('metrics_retention', function ($value) {
            return $value >= 3600 && $value <= 2592000; // 1 hour to 30 days
        });
    }

    /**
     * Configure worker options
     */
    private function configureWorkersOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'auto_scale' => false,
            'min_workers' => 1,
            'max_workers' => 10,
            'process' => [
                'memory_limit' => '256M',
                'timeout' => 3600,
                'restart_threshold' => 1000,
            ],
        ]);

        $resolver->setAllowedTypes('auto_scale', 'bool');
        $resolver->setAllowedTypes('min_workers', 'int');
        $resolver->setAllowedTypes('max_workers', 'int');
        $resolver->setAllowedTypes('process', 'array');

        $resolver->setAllowedValues('min_workers', function ($value) {
            return $value >= 1 && $value <= 100;
        });

        $resolver->setAllowedValues('max_workers', function ($value) {
            return $value >= 1 && $value <= 100;
        });

        // Validate max_workers >= min_workers
        $resolver->setNormalizer('max_workers', function ($options, $value) {
            if ($value < $options['min_workers']) {
                throw new \InvalidArgumentException(
                    'max_workers must be greater than or equal to min_workers'
                );
            }
            return $value;
        });

        // Validate process configuration
        $resolver->setNormalizer('process', function ($options, $process) {
            $processResolver = new OptionsResolver();
            $processResolver->setDefaults([
                'memory_limit' => '256M',
                'timeout' => 3600,
                'restart_threshold' => 1000,
            ]);

            $processResolver->setAllowedTypes('memory_limit', 'string');
            $processResolver->setAllowedTypes('timeout', 'int');
            $processResolver->setAllowedTypes('restart_threshold', 'int');

            return $processResolver->resolve($process);
        });
    }

    /**
     * Get the default connection name
     */
    public function getDefaultConnection(): string
    {
        return $this->getOption('default');
    }

    /**
     * Get connection configuration
     */
    public function getConnection(string $name): array
    {
        $connections = $this->getOption('connections');

        if (!isset($connections[$name])) {
            throw new \InvalidArgumentException("Connection '{$name}' not found");
        }

        return $connections[$name];
    }

    /**
     * Get all connections
     */
    public function getConnections(): array
    {
        return $this->getOption('connections');
    }

    /**
     * Check if a connection exists
     */
    public function hasConnection(string $name): bool
    {
        $connections = $this->getOption('connections');
        return isset($connections[$name]);
    }

    /**
     * Get monitoring configuration
     */
    public function getMonitoringConfig(): array
    {
        return $this->getOption('monitoring');
    }

    /**
     * Get worker configuration
     */
    public function getWorkersConfig(): array
    {
        return $this->getOption('workers');
    }
}
