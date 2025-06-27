<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Cache\CacheStore;
use Glueful\Helpers\CacheHelper;
use Redis;

/**
 * Rate Limiter Distributor
 *
 * Coordinates rate limiting across multiple nodes in a distributed environment.
 * Uses Redis for cross-node synchronization and global rate limit coordination.
 */
class RateLimiterDistributor
{
    /** @var string Key prefix for rate limiter distributor */
    private const PREFIX = 'rate_limit_distributor:';

    /** @var string Node registration key */
    private const NODES_KEY = 'nodes';

    /** @var string Global rate limits key */
    private const GLOBAL_LIMITS_KEY = 'global_limits';

    /** @var string Lock key prefix */
    private const LOCK_PREFIX = 'lock:';

    /** @var CacheStore Cache store instance */
    private CacheStore $cache;

    /** @var string Node identifier */
    private string $nodeId;

    /** @var Redis|null Redis connection for pub/sub */
    private ?Redis $redis = null;

    /** @var bool Whether this node is the primary coordinator */
    private bool $isPrimaryCoordinator = false;

    /** @var int Synchronization interval in seconds */
    private int $syncInterval = 30;

    /**
     * Constructor
     *
     * @param CacheStore|null $cache Cache store instance
     * @param string $nodeId Node identifier (defaults to hostname)
     * @param int $syncInterval Synchronization interval in seconds
     */
    public function __construct(?CacheStore $cache = null, string $nodeId = '', int $syncInterval = 30)
    {
        // Set up cache - try provided instance or get from container
        $this->cache = $cache;

        // If no cache provided, try to get from helper
        if ($this->cache === null) {
            $this->cache = CacheHelper::createCacheInstance();
            if ($this->cache === null) {
                throw new \RuntimeException(
                    'CacheStore is required for rate limiter distributor: Unable to create cache instance.'
                );
            }
        }

        // Use hostname as node ID if not provided
        $this->nodeId = $nodeId ?: gethostname() ?: uniqid('node-');
        $this->syncInterval = $syncInterval;

        // Attempt to connect to Redis for pub/sub if available
        $this->connectToRedis();

        // Register this node
        $this->registerNode();
    }

    /**
     * Register this node with the distributor
     */
    public function registerNode(): void
    {
        $nodeData = [
            'id' => $this->nodeId,
            'hostname' => gethostname() ?: '',
            'ip' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'last_seen' => time(),
            'version' => config('app.version_full', '1.0.0'),
        ];

        // Store node data in cache
        $nodesKey = self::NODES_KEY;
        $this->cache->set($nodesKey . ':' . $this->nodeId, $nodeData);

        // Try to elect primary coordinator if not already determined
        $this->tryElectPrimaryCoordinator();
    }

    /**
     * Update global rate limit data
     *
     * @param string $key Rate limit key
     * @param int $currentCount Current count of attempts
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @return bool Success status
     */
    public function updateGlobalLimit(string $key, int $currentCount, int $maxAttempts, int $windowSeconds): bool
    {
        if (!$this->acquireLock($key, 2)) {
            return false;
        }

        try {
            $limitsKey = self::GLOBAL_LIMITS_KEY;
            $limitData = [
                'key' => $key,
                'count' => $currentCount,
                'max' => $maxAttempts,
                'window' => $windowSeconds,
                'updated_at' => time(),
                'node_id' => $this->nodeId,
            ];

            $this->cache->set($limitsKey . ':' . $key, $limitData);

            // Publish update if Redis pub/sub is available
            if ($this->redis) {
                $this->redis->publish(
                    'limit_updates',
                    json_encode(['action' => 'update', 'data' => $limitData])
                );
            }

            return true;
        } finally {
            $this->releaseLock($key);
        }
    }

    /**
     * Get global rate limit data
     *
     * @param string $key Rate limit key
     * @return array|null Rate limit data or null if not found
     */
    public function getGlobalLimit(string $key): ?array
    {
        $limitsKey = self::GLOBAL_LIMITS_KEY;
        $data = $this->cache->get($limitsKey . ':' . $key);

        if ($data) {
            return is_array($data) ? $data : null;
        }

        return null;
    }

    /**
     * Synchronize with global rate limit state
     *
     * This method pulls all global rate limits and updates local state.
     * It's called periodically by the primary coordinator.
     *
     * @return int Number of synchronized limits
     */
    public function synchronizeGlobalLimits(): int
    {
        if (!$this->isPrimaryCoordinator) {
            return 0;
        }

        // We can't easily list all keys with the standard cache interface
        // Instead, let's use the direct Redis connection if available
        if (!$this->redis) {
            return 0;
        }

        $limitsKey = self::GLOBAL_LIMITS_KEY;
        $keys = $this->redis->keys($limitsKey . ':*');

        if (!$keys || !is_array($keys)) {
            return 0;
        }

        $count = 0;
        $now = time();
        $cleanupKeys = [];

        foreach ($keys as $fullKey) {
            // Extract the key part after the prefix
            $key = str_replace($limitsKey . ':', '', $fullKey);

            $data = $this->cache->get($limitsKey . ':' . $key);
            if (!$data || !is_array($data)) {
                continue;
            }

            // Check if this limit is stale and should be removed
            if (isset($data['updated_at']) && ($now - $data['updated_at']) > 86400) {
                $cleanupKeys[] = $key;
                continue;
            }

            $count++;
        }

        // Clean up stale entries
        if (!empty($cleanupKeys) && $this->acquireLock('cleanup', 5)) {
            try {
                foreach ($cleanupKeys as $key) {
                    $this->cache->delete($limitsKey . ':' . $key);
                }
            } finally {
                $this->releaseLock('cleanup');
            }
        }

        return $count;
    }

    /**
     * Clean up inactive nodes
     *
     * Removes nodes that haven't reported in for a while.
     *
     * @param int $maxAgeSeconds Maximum node age in seconds
     * @return int Number of nodes removed
     */
    public function cleanupInactiveNodes(int $maxAgeSeconds = 300): int
    {
        if (!$this->isPrimaryCoordinator) {
            return 0;
        }

        if (!$this->acquireLock('node_cleanup', 5)) {
            return 0;
        }

        try {
            $nodesKey = self::NODES_KEY;

            // We need to manually get all node keys since we don't have hgetall
            if (!$this->redis) {
                return 0;
            }

            $nodeKeys = $this->redis->keys($nodesKey . ':*');
            if (!$nodeKeys || !is_array($nodeKeys)) {
                return 0;
            }

            $count = 0;
            $now = time();

            foreach ($nodeKeys as $fullKey) {
                $nodeId = str_replace($nodesKey . ':', '', $fullKey);
                $nodeData = $this->cache->get($nodesKey . ':' . $nodeId);

                if (!$nodeData || !is_array($nodeData) || !isset($nodeData['last_seen'])) {
                    continue;
                }

                if (($now - $nodeData['last_seen']) > $maxAgeSeconds) {
                    $this->cache->delete($nodesKey . ':' . $nodeId);
                    $count++;
                }
            }

            if ($count > 0) {
                // Re-elect primary coordinator if needed
                $this->tryElectPrimaryCoordinator();
            }

            return $count;
        } finally {
            $this->releaseLock('node_cleanup');
        }
    }

    /**
     * Try to elect this node as primary coordinator
     *
     * @return bool True if this node is now the primary coordinator
     */
    private function tryElectPrimaryCoordinator(): bool
    {
        if (!$this->acquireLock('coordinator_election', 5)) {
            return false;
        }

        try {
            $nodesKey = self::NODES_KEY;

            // We need to manually get all node keys since we don't have hgetall
            if (!$this->redis) {
                // No Redis connection, this node becomes primary
                $this->isPrimaryCoordinator = true;
                $this->cache->set('primary_coordinator', $this->nodeId, 300);
                return true;
            }

            $nodeKeys = $this->redis->keys($nodesKey . ':*');
            if (!$nodeKeys || !is_array($nodeKeys) || count($nodeKeys) === 0) {
                // No nodes registered yet, this node becomes primary
                $this->isPrimaryCoordinator = true;
                $this->cache->set('primary_coordinator', $this->nodeId, 300);
                return true;
            }

            // Check if there's already a primary coordinator
            $primaryId = $this->cache->get('primary_coordinator');

            if ($primaryId === $this->nodeId) {
                // We're already the primary coordinator
                $this->cache->set('primary_coordinator', $this->nodeId, 300);
                $this->isPrimaryCoordinator = true;
                return true;
            }

            // Check if the primary coordinator node still exists
            if ($primaryId) {
                $primaryNodeExists = (bool)$this->cache->get($nodesKey . ':' . $primaryId);
                if ($primaryNodeExists) {
                    // There's already an active primary coordinator
                    $this->isPrimaryCoordinator = ($primaryId === $this->nodeId);
                    return $this->isPrimaryCoordinator;
                }
            }

            // No active primary coordinator, elect one (oldest node by ID)
            $allNodeIds = [];
            foreach ($nodeKeys as $fullKey) {
                $nodeId = str_replace($nodesKey . ':', '', $fullKey);
                $allNodeIds[] = $nodeId;
            }

            sort($allNodeIds);
            $firstNodeId = count($allNodeIds) > 0 ? $allNodeIds[0] : $this->nodeId;

            $this->isPrimaryCoordinator = ($firstNodeId === $this->nodeId);
            if ($this->isPrimaryCoordinator) {
                $this->cache->set('primary_coordinator', $this->nodeId, 300);
            }

            return $this->isPrimaryCoordinator;
        } finally {
            $this->releaseLock('coordinator_election');
        }
    }

    /**
     * Check if this node is the primary coordinator
     *
     * @return bool True if this node is the primary coordinator
     */
    public function isPrimaryCoordinator(): bool
    {
        return $this->isPrimaryCoordinator;
    }

    /**
     * Get all registered nodes
     *
     * @return array Array of node data
     */
    public function getNodes(): array
    {
        $nodesKey = self::NODES_KEY;

        // We need to manually get all node keys since we don't have hgetall
        if (!$this->redis) {
            return [];
        }

        $nodeKeys = $this->redis->keys($nodesKey . ':*');
        if (!$nodeKeys || !is_array($nodeKeys)) {
            return [];
        }

        $result = [];
        foreach ($nodeKeys as $fullKey) {
            $nodeId = str_replace(self::PREFIX . $nodesKey . ':', '', $fullKey);
            $nodeData = $this->cache->get($nodesKey . ':' . $nodeId);

            if ($nodeData && is_array($nodeData)) {
                $result[$nodeId] = $nodeData;
            }
        }

        return $result;
    }

    /**
     * Acquire a distributed lock
     *
     * @param string $key Lock key
     * @param int $timeout Timeout in seconds
     * @return bool True if lock acquired
     */
    private function acquireLock(string $key, int $timeout = 5): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;
        $token = bin2hex(random_bytes(8));
        // Use setNx for atomic lock acquisition
        $acquired = $this->cache->setNx(
            $lockKey,
            $token,
            $timeout
        );

        if ($acquired) {
            return true;
        }

        return false;
    }

    /**
     * Release a distributed lock
     *
     * @param string $key Lock key
     */
    private function releaseLock(string $key): void
    {
        $lockKey = self::LOCK_PREFIX . $key;
        $this->cache->delete($lockKey);
    }

    /**
     * Connect to Redis for pub/sub functionality
     */
    private function connectToRedis(): void
    {
        try {
            $this->redis = new Redis();
            $host = config('cache.stores.redis.host') ?: env('REDIS_HOST', '127.0.0.1');
            $port = (int)(config('cache.stores.redis.port') ?: env('REDIS_PORT', 6379));
            $password = config('cache.stores.redis.password') ?: env('REDIS_PASSWORD');
            $database = (int)(config('cache.stores.redis.database') ?: env('REDIS_DB', 0));

            if ($this->redis->connect($host, $port, 2.5)) {
                if ($password) {
                    $this->redis->auth($password);
                }
                $this->redis->select($database);
            } else {
                $this->redis = null;
            }
        } catch (\Exception $e) {
            $this->redis = null;
        }
    }
}
