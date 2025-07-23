<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Config\ConfigurableService;
use Glueful\Database\Driver\DatabaseDriver;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configurable Connection Pool
 *
 * Extension of ConnectionPool that uses OptionsResolver for configuration validation.
 * Provides type-safe configuration with validation and sensible defaults.
 *
 * @package Glueful\Database
 */
class ConfigurableConnectionPool extends ConnectionPool
{
    use \Glueful\Config\ConfigurableTrait;

    /**
     * Create a new configurable connection pool
     *
     * @param array $config Pool configuration options
     * @param string $dsn Database connection string
     * @param string|null $username Database username
     * @param string|null $password Database password
     * @param array $options PDO connection options
     * @param DatabaseDriver $driver Database driver
     */
    public function __construct(
        array $config,
        string $dsn,
        ?string $username,
        ?string $password,
        array $options,
        DatabaseDriver $driver
    ) {
        // Resolve and validate configuration
        $validatedConfig = $this->resolveOptions($config);

        // Call parent constructor with validated config
        parent::__construct(
            $validatedConfig,
            $dsn,
            $username,
            $password,
            $options,
            $driver
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'min_connections' => 2,
            'max_connections' => 10,
            'idle_timeout' => 300,     // 5 minutes
            'max_lifetime' => 3600,    // 1 hour
            'acquisition_timeout' => 30,
            'health_check_interval' => 60,
            'health_check_timeout' => 5,
            'max_use_count' => 1000,
            'retry_attempts' => 3,
            'retry_delay' => 100,      // milliseconds
        ]);

        // Set type constraints
        $resolver->setAllowedTypes('min_connections', 'int');
        $resolver->setAllowedTypes('max_connections', 'int');
        $resolver->setAllowedTypes('idle_timeout', 'int');
        $resolver->setAllowedTypes('max_lifetime', 'int');
        $resolver->setAllowedTypes('acquisition_timeout', 'int');
        $resolver->setAllowedTypes('health_check_interval', 'int');
        $resolver->setAllowedTypes('health_check_timeout', 'int');
        $resolver->setAllowedTypes('max_use_count', 'int');
        $resolver->setAllowedTypes('retry_attempts', 'int');
        $resolver->setAllowedTypes('retry_delay', 'int');

        // Set value constraints
        $resolver->setAllowedValues('min_connections', function ($value) {
            return $value >= 1 && $value <= 100;
        });

        $resolver->setAllowedValues('max_connections', function ($value) {
            return $value >= 1 && $value <= 500;
        });

        $resolver->setAllowedValues('idle_timeout', function ($value) {
            return $value >= 30 && $value <= 86400; // 30 seconds to 24 hours
        });

        $resolver->setAllowedValues('max_lifetime', function ($value) {
            return $value >= 60 && $value <= 172800; // 1 minute to 48 hours
        });

        $resolver->setAllowedValues('acquisition_timeout', function ($value) {
            return $value >= 1 && $value <= 300; // 1 second to 5 minutes
        });

        $resolver->setAllowedValues('health_check_interval', function ($value) {
            return $value >= 10 && $value <= 3600; // 10 seconds to 1 hour
        });

        $resolver->setAllowedValues('health_check_timeout', function ($value) {
            return $value >= 1 && $value <= 60; // 1 to 60 seconds
        });

        $resolver->setAllowedValues('max_use_count', function ($value) {
            return $value >= 1 && $value <= 100000;
        });

        $resolver->setAllowedValues('retry_attempts', function ($value) {
            return $value >= 0 && $value <= 10;
        });

        $resolver->setAllowedValues('retry_delay', function ($value) {
            return $value >= 0 && $value <= 5000; // 0 to 5 seconds in milliseconds
        });

        // Cross-validation: max_connections must be >= min_connections
        $resolver->setNormalizer('max_connections', function ($options, $value) {
            if ($value < $options['min_connections']) {
                throw new \InvalidArgumentException(
                    'max_connections must be greater than or equal to min_connections'
                );
            }
            return $value;
        });

        // Cross-validation: max_lifetime should be greater than idle_timeout
        $resolver->setNormalizer('max_lifetime', function ($options, $value) {
            if ($value <= $options['idle_timeout']) {
                throw new \InvalidArgumentException(
                    'max_lifetime should be greater than idle_timeout'
                );
            }
            return $value;
        });

        // Performance recommendations in normalizers
        $resolver->setNormalizer('min_connections', function ($options, $value) {
            if ($value > 20) {
                trigger_error(
                    'High min_connections value may consume excessive resources',
                    E_USER_NOTICE
                );
            }
            return $value;
        });

        $resolver->setNormalizer('max_connections', function ($options, $value) {
            if ($value > 50) {
                trigger_error(
                    'High max_connections value may impact performance',
                    E_USER_NOTICE
                );
            }
            return $value;
        });
    }

    /**
     * Get pool configuration statistics
     *
     * @return array Configuration summary
     */
    public function getConfigurationSummary(): array
    {
        $config = $this->getOptions();

        return [
            'pool_size' => [
                'min' => $config['min_connections'],
                'max' => $config['max_connections'],
                'ratio' => round($config['min_connections'] / $config['max_connections'], 2),
            ],
            'timeouts' => [
                'idle' => $config['idle_timeout'],
                'lifetime' => $config['max_lifetime'],
                'acquisition' => $config['acquisition_timeout'],
                'health_check' => $config['health_check_timeout'],
            ],
            'maintenance' => [
                'health_check_interval' => $config['health_check_interval'],
                'max_use_count' => $config['max_use_count'],
            ],
            'retry' => [
                'attempts' => $config['retry_attempts'],
                'delay_ms' => $config['retry_delay'],
            ],
            'performance_score' => $this->calculatePerformanceScore($config),
        ];
    }

    /**
     * Calculate a performance score based on configuration
     *
     * @param array $config Resolved configuration
     * @return int Score from 1-100
     */
    private function calculatePerformanceScore(array $config): int
    {
        $score = 100;

        // Deduct points for potential performance issues
        if ($config['min_connections'] > 10) {
            $score -= 10; // High minimum connection overhead
        }

        if ($config['max_connections'] > 30) {
            $score -= 15; // High maximum connections may cause contention
        }

        if ($config['acquisition_timeout'] > 60) {
            $score -= 5; // Long timeouts may indicate poor performance
        }

        if ($config['health_check_interval'] < 30) {
            $score -= 5; // Too frequent health checks
        }

        if ($config['retry_attempts'] > 5) {
            $score -= 5; // Excessive retries may mask problems
        }

        // Add points for good configuration
        if ($config['max_connections'] <= 20 && $config['min_connections'] >= 2) {
            $score += 5; // Well-balanced pool size
        }

        if ($config['max_lifetime'] >= 1800) {
            $score += 5; // Good connection reuse
        }

        return max(1, min(100, $score));
    }
}
