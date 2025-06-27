<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\TokenStorageInterface;
use Glueful\Cache\CacheStore;
use Glueful\Helpers\CacheHelper;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Http\RequestContext;
use Glueful\Helpers\Utils;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Token Storage Service
 *
 * Centralized service for managing authentication tokens across multiple storage layers.
 * Ensures consistency between database and cache, with transaction support and comprehensive error handling.
 */
class TokenStorageService implements TokenStorageInterface
{
    private Connection $connection;
    private QueryBuilder $queryBuilder;
    private ?CacheStore $cache;
    private ?EventDispatcherInterface $eventDispatcher;

    private bool $useTransactions;
    private string $sessionTable = 'auth_sessions';
    private int $cacheDefaultTtl;
    private ?RequestContext $requestContext;

    public function __construct(
        ?CacheStore $cache = null,
        ?Connection $connection = null,
        ?RequestContext $requestContext = null,
        bool $useTransactions = true,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        // Assign dependencies with sensible defaults
        $this->cache = $cache ?? CacheHelper::createCacheInstance();
        $this->connection = $connection ?? new Connection();
        $this->requestContext = $requestContext ?? RequestContext::fromGlobals();
        $this->useTransactions = $useTransactions;
        $this->eventDispatcher = $eventDispatcher;

        // Initialize derived dependencies
        $this->queryBuilder = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());
        $this->cacheDefaultTtl = (int)config('session.access_token_lifetime', 900);
    }


    /**
     * Store a new session with tokens in both database and cache
     */
    public function storeSession(array $sessionData, array $tokens): bool
    {
        try {
            if ($this->useTransactions) {
                $this->connection->getPDO()->beginTransaction();
            }

            // Calculate expiration times
            $accessExpiresAt = date('Y-m-d H:i:s', time() + $tokens['expires_in']);
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + (int)config('session.refresh_token_lifetime', 604800));

            // Prepare session data for database
            $sessionUuid = $sessionData['session_id'] ?? Utils::generateNanoID();
            $dbSessionData = [
                'uuid' => $sessionUuid,
                'user_uuid' => $sessionData['uuid'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'provider' => $sessionData['provider'] ?? 'jwt',
                'user_agent' => $this->requestContext->getUserAgent(),
                'ip_address' => $this->requestContext->getClientIp(),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_token_refresh' => date('Y-m-d H:i:s'),
                'token_fingerprint' => hash('sha256', $tokens['access_token']),
                'remember_me' => !empty($sessionData['remember_me']) ? 1 : 0
            ];

            // Store in database
            $success = $this->storeSessionInDatabase($dbSessionData);
            if (!$success) {
                throw new \Exception('Failed to store session in database');
            }

            // Store in cache if enabled
            if ($this->cache !== null) {
                $this->storeSessionInCache($sessionData, $tokens, $sessionUuid);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            // Dispatch session created event
            if ($this->eventDispatcher) {
                $event = new SessionCreatedEvent($sessionData, $tokens, [
                    'session_uuid' => $sessionUuid,
                    'ip_address' => $this->requestContext->getClientIp(),
                    'user_agent' => $this->requestContext->getUserAgent()
                ]);
                $this->eventDispatcher->dispatch($event);
            }

            // Skip audit logging here - login success is already logged by the auth provider
            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            return false;
        }
    }

    /**
     * Update existing session with new tokens
     */
    public function updateSessionTokens(string $sessionIdentifier, array $newTokens): bool
    {
        try {
            if ($this->useTransactions) {
                $this->connection->getPDO()->beginTransaction();
            }

            // Get existing session data
            $existingSession = $this->getSessionByRefreshToken($sessionIdentifier);
            if (!$existingSession) {
                throw new \Exception('Session not found for update');
            }

            // Calculate new expiration times
            $accessExpiresAt = date('Y-m-d H:i:s', time() + $newTokens['expires_in']);
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + (int)config('session.refresh_token_lifetime', 604800));

            // Update database
            $updateData = [
                'access_token' => $newTokens['access_token'],
                'refresh_token' => $newTokens['refresh_token'],
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'last_token_refresh' => date('Y-m-d H:i:s'),
                'token_fingerprint' => hash('sha256', $newTokens['access_token'])
            ];

            $success = $this->queryBuilder->update(
                $this->sessionTable,
                $updateData,
                ['refresh_token' => $sessionIdentifier, 'status' => 'active']
            );

            if (!$success) {
                throw new \Exception('Failed to update session in database');
            }

            // Update cache if enabled
            if ($this->cache !== null) {
                $this->updateSessionInCache($existingSession, $newTokens);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            return false;
        }
    }

    /**
     * Retrieve session data by access token
     */
    public function getSessionByAccessToken(string $accessToken): ?array
    {
        // Try cache first if enabled
        if ($this->cache !== null) {
            $cacheKey = "session_token:{$accessToken}";
            $cachedSession = $this->resolveCacheReference($cacheKey);
            if ($cachedSession) {
                return json_decode($cachedSession, true);
            }
        }

        // Fallback to database with expiration check
        $now = date('Y-m-d H:i:s');
        $result = $this->queryBuilder
            ->select($this->sessionTable, ['*'])
            ->where(['access_token' => $accessToken, 'status' => 'active'])
            ->whereGreaterThan('access_expires_at', $now)
            ->get();

        if (empty($result)) {
            return null;
        }

        $session = $result[0];

        // Store in cache for future requests
        if ($this->cache !== null && $session) {
            $this->cacheSessionData($session, $accessToken);
        }

        return $session;
    }

    /**
     * Retrieve session data by refresh token
     */
    public function getSessionByRefreshToken(string $refreshToken): ?array
    {
        // Try cache first if enabled
        if ($this->cache !== null) {
            $cacheKey = "session_refresh:{$refreshToken}";
            $cachedSession = $this->resolveCacheReference($cacheKey);
            if ($cachedSession) {
                return json_decode($cachedSession, true);
            }
        }

        // Fallback to database
        $result = $this->queryBuilder
            ->select($this->sessionTable, ['*'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->get();

        if (empty($result)) {
            return null;
        }

        $session = $result[0];

        // Store in cache for future requests
        if ($this->cache !== null && $session) {
            $this->cacheSessionData($session, null, $refreshToken);
        }

        return $session;
    }

    /**
     * Revoke a session and invalidate all its tokens
     */
    public function revokeSession(string $sessionIdentifier): bool
    {
        try {
            if ($this->useTransactions) {
                $this->connection->getPDO()->beginTransaction();
            }

            // Get session for cleanup
            $session = $this->getSessionByRefreshToken($sessionIdentifier)
                ?? $this->getSessionByAccessToken($sessionIdentifier);

            if (!$session) {
                return false;
            }

            // Update database status
            $success = $this->queryBuilder->update(
                $this->sessionTable,
                [
                    'status' => 'revoked'
                ],
                ['uuid' => $session['uuid']]
            );

            if (!$success) {
                throw new \Exception('Failed to revoke session in database');
            }

            // Clear cache entries
            if ($this->cache !== null) {
                $this->clearSessionCache($session);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            // Dispatch session destroyed event
            if ($this->eventDispatcher) {
                $event = new SessionDestroyedEvent(
                    $session['access_token'] ?? $sessionIdentifier,
                    $session['user_uuid'] ?? null,
                    'revoked',
                    [
                        'session_uuid' => $session['uuid'],
                        'ip_address' => $this->requestContext->getClientIp(),
                        'user_agent' => $this->requestContext->getUserAgent()
                    ]
                );
                $this->eventDispatcher->dispatch($event);
            }

            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            return false;
        }
    }

    /**
     * Revoke all sessions for a specific user
     */
    public function revokeAllUserSessions(string $userUuid): bool
    {
        try {
            if ($this->useTransactions) {
                $this->connection->getPDO()->beginTransaction();
            }

            // Get all active sessions for user
            $sessions = $this->queryBuilder
                ->select($this->sessionTable, ['*'])
                ->where(['user_uuid' => $userUuid, 'status' => 'active'])
                ->get();

            // Update all sessions to revoked
            $success = $this->queryBuilder->update(
                $this->sessionTable,
                [
                    'status' => 'revoked',
                    'revoked_at' => date('Y-m-d H:i:s')
                ],
                ['user_uuid' => $userUuid, 'status' => 'active']
            );

            if (!$success) {
                throw new \Exception('Failed to revoke user sessions in database');
            }

            // Clear cache entries for all sessions
            if ($this->cache !== null) {
                foreach ($sessions as $session) {
                    $this->clearSessionCache($session);
                }
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            return false;
        }
    }

    /**
     * Clean up expired sessions and tokens
     */
    public function cleanupExpiredSessions(): int
    {
        $now = date('Y-m-d H:i:s');
        $cleanedCount = 0;

        try {
            // Get expired sessions for cache cleanup using QueryBuilder
            $expiredSessions = $this->queryBuilder
                ->select($this->sessionTable, ['*'])
                ->whereLessThan('refresh_expires_at', $now)
                ->where(['status' => 'active'])
                ->get();

            // Update expired sessions using bulk update to avoid N+1 queries
            if (!empty($expiredSessions)) {
                // Use raw SQL for efficient bulk update with IN clause
                $sessionIds = array_column($expiredSessions, 'session_id');
                $placeholders = str_repeat('?,', count($sessionIds) - 1) . '?';
                $sql = "UPDATE {$this->sessionTable} SET status = 'expired', expired_at = ? " .
                       "WHERE session_id IN ({$placeholders})";
                $params = array_merge([$now], $sessionIds);

                $stmt = $this->connection->getPDO()->prepare($sql);
                $success = $stmt->execute($params);
            } else {
                $success = true; // No sessions to update
            }

            if ($success) {
                $cleanedCount = count($expiredSessions);

                // Clear cache entries
                if ($this->cache !== null) {
                    foreach ($expiredSessions as $session) {
                        $this->clearSessionCache($session);
                    }
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail - cleanup is a maintenance operation
            error_log("Session cleanup failed: " . $e->getMessage());
        }

        return $cleanedCount;
    }

    /**
     * Validate that both storage layers are synchronized
     */
    public function validateStorageConsistency(string $sessionIdentifier): bool
    {
        if ($this->cache === null) {
            return true; // Can't be inconsistent if cache is disabled
        }

        try {
            // Get from database
            $dbSession = $this->getSessionFromDatabase($sessionIdentifier);

            // Get from cache
            $cacheKey = CacheHelper::sessionKey($sessionIdentifier, 'refresh');
            $cachedData = null;
            if ($this->cache !== null) {
                try {
                    $cachedData = $this->cache->get($cacheKey);
                } catch (\Exception $e) {
                    // Cache failure - continue without cache
                    error_log("Cache get failed for key '{$cacheKey}': " . $e->getMessage());
                }
            }
            $cacheSession = $cachedData ? json_decode($cachedData, true) : null;

            // Compare critical fields
            if (!$dbSession && !$cacheSession) {
                return true; // Consistently empty
            }

            if (!$dbSession || !$cacheSession) {
                return false; // One exists, other doesn't
            }

            // Check if tokens match
            return $dbSession['access_token'] === $cacheSession['access_token'] &&
                   $dbSession['refresh_token'] === $cacheSession['refresh_token'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get storage layer health status
     */
    public function getStorageHealth(): array
    {
        $health = [
            'database' => ['status' => 'unknown', 'response_time' => null],
            'cache' => ['status' => 'unknown', 'response_time' => null],
            'overall' => 'unknown'
        ];

        // Test database connectivity using QueryBuilder for portability
        try {
            $start = microtime(true);
            // Use a simple count query on the sessions table to test connectivity
            $this->queryBuilder->count($this->sessionTable, []);
            $health['database'] = [
                'status' => 'healthy',
                'response_time' => round((microtime(true) - $start) * 1000, 2)
            ];
        } catch (\Exception $e) {
            $health['database'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }

        // Test cache connectivity
        if ($this->cache !== null) {
            try {
                $start = microtime(true);
                $this->cache->set('health_check', 'ok', 5);
                $this->cache->get('health_check');
                $health['cache'] = [
                    'status' => 'healthy',
                    'response_time' => round((microtime(true) - $start) * 1000, 2)
                ];
            } catch (\Exception $e) {
                $health['cache'] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage()
                ];
            }
        } else {
            $health['cache'] = ['status' => 'disabled'];
        }

        // Determine overall health
        $dbHealthy = $health['database']['status'] === 'healthy';
        $cacheHealthy = $this->cache === null || $health['cache']['status'] === 'healthy';

        $health['overall'] = ($dbHealthy && $cacheHealthy) ? 'healthy' : 'degraded';

        return $health;
    }

    // Private helper methods

    private function storeSessionInDatabase(array $sessionData): bool
    {
        $result = $this->queryBuilder->insert($this->sessionTable, $sessionData);
        return $result > 0;
    }

    private function storeSessionInCache(array $sessionData, array $tokens, string $sessionId): void
    {
        // Store session data with access token as key
        $accessCacheKey = "session_token:{$tokens['access_token']}";
        $refreshCacheKey = "session_refresh:{$tokens['refresh_token']}";

        $cacheData = array_merge($sessionData, [
            'session_id' => $sessionId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token']
        ]);

        // Store session data once with canonical key and use references
        $canonicalKey = "session_data:{$cacheData['session_id']}";
        $sessionDataJson = json_encode($cacheData);
        $refreshTtl = (int)config('session.refresh_token_lifetime', 604800);
        $maxTtl = max($this->cacheDefaultTtl, $refreshTtl);

        // Store the actual data once with the longest TTL
        $this->cache->set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        $this->cache->set($accessCacheKey, $canonicalKey, $this->cacheDefaultTtl);
        $this->cache->set($refreshCacheKey, $canonicalKey, $refreshTtl);
    }

    private function updateSessionInCache(array $sessionData, array $newTokens): void
    {
        // Remove old cache entries
        $this->cache->delete("session_token:{$sessionData['access_token']}");
        $this->cache->delete("session_refresh:{$sessionData['refresh_token']}");

        // Store new cache entries using reference pattern
        $updatedData = array_merge($sessionData, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token']
        ]);

        $canonicalKey = "session_data:{$updatedData['session_id']}";
        $sessionDataJson = json_encode($updatedData);
        $refreshTtl = (int)config('session.refresh_token_lifetime', 604800);
        $maxTtl = max($this->cacheDefaultTtl, $refreshTtl);

        // Store the actual data once with the longest TTL
        $this->cache->set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        $this->cache->set("session_token:{$newTokens['access_token']}", $canonicalKey, $this->cacheDefaultTtl);
        $this->cache->set("session_refresh:{$newTokens['refresh_token']}", $canonicalKey, $refreshTtl);
    }

    private function cacheSessionData(array $session, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (!$accessToken && !$refreshToken) {
            return;
        }

        $canonicalKey = "session_data:{$session['session_id']}";
        $sessionDataJson = json_encode($session);
        $refreshTtl = (int)config('session.refresh_token_lifetime', 604800);
        $maxTtl = max($this->cacheDefaultTtl, $refreshTtl);

        // Store the actual data once with the longest TTL
        $this->cache->set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        if ($accessToken) {
            $this->cache->set("session_token:{$accessToken}", $canonicalKey, $this->cacheDefaultTtl);
        }

        if ($refreshToken) {
            $this->cache->set("session_refresh:{$refreshToken}", $canonicalKey, $refreshTtl);
        }
    }

    /**
     * Resolve cache reference to actual data
     *
     * @param string $key Cache key that may contain a reference
     * @return string|null The actual cache data or null if not found
     */
    private function resolveCacheReference(string $key): ?string
    {
        $cachedValue = $this->cache->get($key);

        if ($cachedValue === null) {
            return null;
        }

        // Check if this is a reference (starts with session_data:)
        if (is_string($cachedValue) && strpos($cachedValue, 'session_data:') === 0) {
            // This is a reference, resolve it
            return $this->cache->get($cachedValue);
        }

        // This is the actual data
        return $cachedValue;
    }

    private function clearSessionCache(array $session): void
    {
        if (isset($session['access_token'])) {
            $this->cache->delete("session_token:{$session['access_token']}");
        }

        if (isset($session['refresh_token'])) {
            $this->cache->delete("session_refresh:{$session['refresh_token']}");
        }

        // Also clear the canonical session data
        if (isset($session['session_id'])) {
            $this->cache->delete("session_data:{$session['session_id']}");
        }
    }

    private function getSessionFromDatabase(string $sessionIdentifier): ?array
    {
        // Try refresh token first
        $result = $this->queryBuilder
            ->select($this->sessionTable, ['*'])
            ->where(['refresh_token' => $sessionIdentifier, 'status' => 'active'])
            ->get();

        if (!empty($result)) {
            return $result[0];
        }

        // Try access token
        $result = $this->queryBuilder
            ->select($this->sessionTable, ['*'])
            ->where(['access_token' => $sessionIdentifier, 'status' => 'active'])
            ->get();

        return !empty($result) ? $result[0] : null;
    }
}
