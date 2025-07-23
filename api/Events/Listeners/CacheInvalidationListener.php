<?php

declare(strict_types=1);

namespace Glueful\Events\Listeners;

use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\Database\EntityCreatedEvent;
use Glueful\Events\Database\EntityUpdatedEvent;
use Glueful\Events\Cache\CacheInvalidatedEvent;
use Glueful\Cache\CacheStore;
use Glueful\Events\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cache Invalidation Event Listener
 *
 * Automatically invalidates cache when data changes.
 * Uses intelligent cache key patterns to invalidate related cache entries.
 *
 * @package Glueful\Events\Listeners
 */
class CacheInvalidationListener implements EventSubscriberInterface
{
    /** @var array Cache invalidation patterns by table */
    private array $invalidationPatterns = [
        'users' => [
            'user:{id}',
            'user_permissions:{id}',
            'active_users',
            'user_count'
        ],
        'sessions' => [
            'session_token:*',
            'user_sessions:{user_id}',
            'active_sessions'
        ],
        'permissions' => [
            'permission:{id}',
            'user_permissions:*',
            'role_permissions:*'
        ],
        'roles' => [
            'role:{id}',
            'role_permissions:*',
            'user_roles:*'
        ]
    ];

    public function __construct(
        private CacheStore $cache
    ) {
    }

    /**
     * Define subscribed events
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EntityCreatedEvent::class => 'onEntityCreated',
            EntityUpdatedEvent::class => 'onEntityUpdated',
            SessionCreatedEvent::class => 'onSessionCreated',
            SessionDestroyedEvent::class => 'onSessionDestroyed',
        ];
    }

    /**
     * Handle entity created events
     */
    public function onEntityCreated(EntityCreatedEvent $event): void
    {
        $this->invalidateCacheForEntity($event->getTable(), $event->getEntityId(), 'create');
    }

    /**
     * Handle entity updated events
     */
    public function onEntityUpdated(EntityUpdatedEvent $event): void
    {
        $this->invalidateCacheForEntity($event->getTable(), $event->getEntityId(), 'update');
    }

    /**
     * Handle session created events
     */
    public function onSessionCreated(SessionCreatedEvent $event): void
    {
        // Invalidate user-related cache
        if ($userUuid = $event->getUserUuid()) {
            $invalidatedKeys = [];

            // Invalidate specific user cache keys
            $keysToInvalidate = [
                "user_data:{$userUuid}",
                "user_sessions:{$userUuid}",
                'active_sessions'
            ];

            foreach ($keysToInvalidate as $key) {
                if ($this->cache->delete($key)) {
                    $invalidatedKeys[] = $key;
                }
            }

            $this->dispatchCacheInvalidationEvent($invalidatedKeys, 'session_created', [
                'user_uuid' => $userUuid,
                'session_data' => $event->getSessionData()
            ]);
        }
    }

    /**
     * Handle session destroyed events
     */
    public function onSessionDestroyed(SessionDestroyedEvent $event): void
    {
        // Invalidate session and user cache
        if ($userUuid = $event->getUserUuid()) {
            $invalidatedKeys = [];

            $keysToInvalidate = [
                "user_sessions:{$userUuid}",
                'active_sessions'
            ];

            foreach ($keysToInvalidate as $key) {
                if ($this->cache->delete($key)) {
                    $invalidatedKeys[] = $key;
                }
            }

            $this->dispatchCacheInvalidationEvent($invalidatedKeys, 'session_destroyed', [
                'user_uuid' => $userUuid
            ]);
        }
    }

    /**
     * Invalidate cache for an entity
     *
     * @param string $table Table name
     * @param string $entityId Entity ID
     * @param string $operation Operation type (create, update, delete)
     */
    private function invalidateCacheForEntity(string $table, string $entityId, string $operation): void
    {
        // Get invalidation patterns for this table
        $patterns = $this->invalidationPatterns[$table] ?? [];

        // Add default patterns
        $patterns[] = "{$table}:{$entityId}";
        $patterns[] = "{$table}_list";
        $patterns[] = "{$table}_count";

        $invalidatedKeys = [];

        foreach ($patterns as $pattern) {
            $cacheKeys = $this->resolveCachePattern($pattern, $entityId);

            foreach ($cacheKeys as $cacheKey) {
                if ($this->cache->delete($cacheKey)) {
                    $invalidatedKeys[] = $cacheKey;
                }
            }
        }

        $this->dispatchCacheInvalidationEvent($invalidatedKeys, "entity_{$operation}", [
            'table' => $table,
            'entity_id' => $entityId,
            'operation' => $operation
        ]);
    }

    /**
     * Resolve cache pattern to actual cache keys
     *
     * @param string $pattern Cache key pattern
     * @param string $entityId Entity ID
     * @return array Array of cache keys
     */
    private function resolveCachePattern(string $pattern, string $entityId): array
    {
        // Replace placeholders
        $resolvedPattern = str_replace(['{id}', '{user_id}'], $entityId, $pattern);

        // Handle wildcard patterns
        if (str_contains($resolvedPattern, '*')) {
            // Use cache's deletePattern method if available
            if (method_exists($this->cache, 'deletePattern')) {
                $this->cache->deletePattern($resolvedPattern);
                return [$resolvedPattern]; // Return pattern for logging
            }

            // Fallback: try to get all keys matching pattern
            return $this->getKeysMatchingPattern($resolvedPattern);
        }

        return [$resolvedPattern];
    }

    /**
     * Get keys matching a wildcard pattern (limited implementation)
     *
     * @param string $pattern Pattern with wildcards
     * @return array Matching keys
     */
    private function getKeysMatchingPattern(string $pattern): array
    {
        // This is a simplified implementation - in production you might want
        // to use Redis SCAN or maintain a key registry
        $keys = [];

        // Convert pattern to regex
        $regex = str_replace(['*', ':'], ['.*', '\\:'], $pattern);
        $regex = "/^{$regex}$/";

        // If cache has a getAllKeys method, use it
        if (method_exists($this->cache, 'getAllKeys')) {
            foreach ($this->cache->getAllKeys() as $key) {
                if (preg_match($regex, $key)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Dispatch cache invalidation event
     *
     * @param array $invalidatedKeys Keys that were invalidated
     * @param string $reason Reason for invalidation
     * @param array $metadata Additional metadata
     */
    private function dispatchCacheInvalidationEvent(array $invalidatedKeys, string $reason, array $metadata = []): void
    {
        if (!empty($invalidatedKeys)) {
            $event = new CacheInvalidatedEvent($invalidatedKeys, array_merge($metadata, [
                'reason' => $reason,
                'timestamp' => time(),
                'count' => count($invalidatedKeys)
            ]));
            Event::dispatch($event);
        }
    }

    /**
     * Add custom invalidation pattern for a table
     *
     * @param string $table Table name
     * @param array $patterns Cache key patterns
     */
    public function addInvalidationPatterns(string $table, array $patterns): void
    {
        if (!isset($this->invalidationPatterns[$table])) {
            $this->invalidationPatterns[$table] = [];
        }

        $this->invalidationPatterns[$table] = array_merge(
            $this->invalidationPatterns[$table],
            $patterns
        );
    }

    /**
     * Get invalidation patterns for a table
     *
     * @param string $table Table name
     * @return array Patterns
     */
    public function getInvalidationPatterns(string $table): array
    {
        return $this->invalidationPatterns[$table] ?? [];
    }

    /**
     * Clear all invalidation patterns
     */
    public function clearInvalidationPatterns(): void
    {
        $this->invalidationPatterns = [];
    }
}
