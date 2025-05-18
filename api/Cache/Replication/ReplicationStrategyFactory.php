<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Replication\ReplicationStrategyInterface;
use Glueful\Cache\Replication\ConsistentHashingStrategy;
use Glueful\Cache\Replication\FullReplicationStrategy;
use Glueful\Cache\Replication\PrimaryReplicaStrategy;
use Glueful\Cache\Replication\KeyPatternShardingStrategy;

/**
 * Replication Strategy Factory
 *
 * Creates and configures replication strategy instances.
 */
class ReplicationStrategyFactory
{
    /** @var array Registered strategies */
    private static array $strategies = [];

    /**
     * Get a replication strategy instance
     *
     * @param string $strategy Strategy name
     * @param array $config Configuration for the strategy
     * @return ReplicationStrategyInterface Strategy instance
     * @throws \InvalidArgumentException If strategy is unknown
     */
    public static function getStrategy(string $strategy, array $config = []): ReplicationStrategyInterface
    {
        // If we have a pre-configured instance, return it
        if (isset(self::$strategies[$strategy])) {
            return self::$strategies[$strategy];
        }

        // Otherwise, create a new instance
        return match ($strategy) {
            'consistent-hashing' => self::createConsistentHashingStrategy($config),
            'full-replication' => new FullReplicationStrategy(),
            'primary-replica' => self::createPrimaryReplicaStrategy($config),
            'key-pattern-sharding' => self::createKeyPatternShardingStrategy($config),
            default => throw new \InvalidArgumentException("Unknown replication strategy: {$strategy}")
        };
    }

    /**
     * Register a custom strategy instance
     *
     * @param string $name Strategy name
     * @param ReplicationStrategyInterface $strategy Strategy instance
     * @return void
     */
    public static function registerStrategy(string $name, ReplicationStrategyInterface $strategy): void
    {
        self::$strategies[$name] = $strategy;
    }

    /**
     * Create a consistent hashing strategy
     *
     * @param array $config Configuration
     * @return ConsistentHashingStrategy
     */
    private static function createConsistentHashingStrategy(array $config): ConsistentHashingStrategy
    {
        $virtualNodes = $config['virtualNodes'] ?? 64;
        $replicas = $config['replicas'] ?? 2;

        return new ConsistentHashingStrategy($virtualNodes, $replicas);
    }

    /**
     * Create a primary-replica strategy
     *
     * @param array $config Configuration
     * @return PrimaryReplicaStrategy
     */
    private static function createPrimaryReplicaStrategy(array $config): PrimaryReplicaStrategy
    {
        $maxReplicas = $config['maxReplicas'] ?? 2;

        return new PrimaryReplicaStrategy($maxReplicas);
    }

    /**
     * Create a key pattern sharding strategy
     *
     * @param array $config Configuration
     * @return KeyPatternShardingStrategy
     */
    private static function createKeyPatternShardingStrategy(array $config): KeyPatternShardingStrategy
    {
        $patternMap = $config['patterns'] ?? [];
        $fallbackName = $config['fallback'] ?? 'consistent-hashing';
        $fallbackConfig = $config['fallbackConfig'] ?? [];

        // Create fallback strategy
        $fallback = self::getStrategy($fallbackName, $fallbackConfig);

        $strategy = new KeyPatternShardingStrategy([], $fallback);

        // Add patterns
        foreach ($patternMap as $pattern => $selector) {
            $strategy->addPattern($pattern, $selector);
        }

        return $strategy;
    }
}
