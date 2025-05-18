<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Sharded by Key Pattern Strategy
 *
 * Routes cache operations based on key pattern matching.
 * Allows optimizing node usage based on data access patterns.
 * Useful for specialized caching needs and performance optimization.
 */
class KeyPatternShardingStrategy implements ReplicationStrategyInterface
{
    /** @var array Mapping of patterns to node selectors */
    private $patternMap = [];

    /** @var ReplicationStrategyInterface Fallback strategy for keys not matching any pattern */
    private $fallbackStrategy;

    /**
     * Initialize strategy
     *
     * @param array $patternMap Pattern-to-nodeset mapping
     * @param ReplicationStrategyInterface $fallbackStrategy Fallback strategy
     */
    public function __construct(array $patternMap = [], ?ReplicationStrategyInterface $fallbackStrategy = null)
    {
        $this->patternMap = $patternMap;
        $this->fallbackStrategy = $fallbackStrategy ?: new ConsistentHashingStrategy();
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesForKey(string $key, array $allNodes): array
    {
        if (empty($allNodes)) {
            return [];
        }

        // Check for pattern matches
        foreach ($this->patternMap as $pattern => $nodeSelector) {
            if ($this->keyMatchesPattern($key, $pattern)) {
                // If the selector is a callback, use it
                if (is_callable($nodeSelector)) {
                    return call_user_func($nodeSelector, $key, $allNodes);
                }

                // If the selector is an array of node IDs, select those nodes
                if (is_array($nodeSelector)) {
                    return $this->selectNodesByIds($nodeSelector, $allNodes);
                }

                // If the selector is a string (node ID), select that node
                if (is_string($nodeSelector)) {
                    return $this->selectNodesByIds([$nodeSelector], $allNodes);
                }
            }
        }

        // Fallback to default strategy if no patterns match
        return $this->fallbackStrategy->getNodesForKey($key, $allNodes);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'key-pattern-sharding';
    }

    /**
     * Check if a key matches a pattern
     *
     * @param string $key Key to check
     * @param string $pattern Pattern to match against
     * @return bool True if key matches pattern
     */
    private function keyMatchesPattern(string $key, string $pattern): bool
    {
        // Simple wildcard pattern matching
        // ^ anchors the match at the beginning
        // $ anchors the match at the end
        // * matches any sequence of characters
        // Convert pattern to a regex
        $regex = '/^' . str_replace(['*', '.'], ['.*', '\.'], $pattern) . '$/';
        return (bool) preg_match($regex, $key);
    }

    /**
     * Select nodes by their IDs
     *
     * @param array $nodeIds Node IDs to select
     * @param array $allNodes All available nodes
     * @return array Selected nodes
     */
    private function selectNodesByIds(array $nodeIds, array $allNodes): array
    {
        $result = [];
        $nodeMap = [];

        // Create ID to node mapping
        foreach ($allNodes as $node) {
            $nodeMap[$node->getId()] = $node;
        }

        // Select nodes by ID
        foreach ($nodeIds as $id) {
            if (isset($nodeMap[$id])) {
                $result[] = $nodeMap[$id];
            }
        }

        return $result;
    }

    /**
     * Add a pattern-based routing rule
     *
     * @param string $pattern Key pattern (can use * as wildcard)
     * @param mixed $nodeSelector Node ID, array of IDs, or callback function
     * @return self
     */
    public function addPattern(string $pattern, $nodeSelector): self
    {
        $this->patternMap[$pattern] = $nodeSelector;
        return $this;
    }

    /**
     * Remove a pattern
     *
     * @param string $pattern Pattern to remove
     * @return self
     */
    public function removePattern(string $pattern): self
    {
        if (isset($this->patternMap[$pattern])) {
            unset($this->patternMap[$pattern]);
        }
        return $this;
    }

    /**
     * Set fallback strategy
     *
     * @param ReplicationStrategyInterface $strategy Fallback strategy
     * @return self
     */
    public function setFallbackStrategy(ReplicationStrategyInterface $strategy): self
    {
        $this->fallbackStrategy = $strategy;
        return $this;
    }
}
