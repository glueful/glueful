<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Interfaces\TokenStorageInterface;
use Glueful\Cache\CacheEngine;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Http\RequestContext;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Glueful\Helpers\Utils;

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
    private AuditLogger $auditLogger;

    private bool $useTransactions;
    private bool $cacheEnabled;
    private string $sessionTable = 'auth_sessions';
    private int $cacheDefaultTtl;
    private ?RequestContext $requestContext;

    public function __construct(
        ?Connection $connection = null,
        bool $useTransactions = true,
        bool $cacheEnabled = true,
        ?RequestContext $requestContext = null
    ) {
        $this->connection = $connection ?? new Connection();
        $this->queryBuilder = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());
        $this->auditLogger = AuditLogger::getInstance();
        $this->requestContext = $requestContext ?? RequestContext::fromGlobals();

        $this->useTransactions = $useTransactions;
        $this->cacheEnabled = $cacheEnabled;
        $this->cacheDefaultTtl = (int)config('session.access_token_lifetime', 900);

        // Initialize cache engine if enabled
        if ($this->cacheEnabled) {
            CacheEngine::initialize();
        }
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
            if ($this->cacheEnabled) {
                $this->storeSessionInCache($sessionData, $tokens, $sessionUuid);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            // Skip audit logging here - login success is already logged by the auth provider
            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_storage_failed',
                AuditEvent::SEVERITY_ERROR,
                [
                    'user_id' => $sessionData['uuid'] ?? null,
                    'session_action' => 'store_new_session',
                    'error' => $e->getMessage(),
                    'ip_address' => $this->requestContext->getClientIp(),
                ]
            );

            return false;
        }
    }

    /**
     * Update existing session with new tokens
     */
    public function updateSessionTokens(string $sessionIdentifier, array $newTokens): bool
    {
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_AUTH,
            'token_update_attempt',
            AuditEvent::SEVERITY_INFO,
            [
                'session_identifier' => substr($sessionIdentifier, 0, 8) . '...',
                'session_action' => 'update_tokens',
                'ip_address' => $this->requestContext->getClientIp(),
            ]
        );

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
            if ($this->cacheEnabled) {
                $this->updateSessionInCache($existingSession, $newTokens);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_update_success',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_id' => $existingSession['user_uuid'],
                    'session_action' => 'update_tokens',
                ]
            );

            return true;
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                $this->connection->getPDO()->rollBack();
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_update_failed',
                AuditEvent::SEVERITY_ERROR,
                [
                    'session_identifier' => substr($sessionIdentifier, 0, 8) . '...',
                    'session_action' => 'update_tokens',
                    'error' => $e->getMessage(),
                    'ip_address' => $this->requestContext->getClientIp(),
                ]
            );

            return false;
        }
    }

    /**
     * Retrieve session data by access token
     */
    public function getSessionByAccessToken(string $accessToken): ?array
    {
        // Try cache first if enabled
        if ($this->cacheEnabled) {
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
        if ($this->cacheEnabled && $session) {
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
        if ($this->cacheEnabled) {
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
        if ($this->cacheEnabled && $session) {
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
            if ($this->cacheEnabled) {
                $this->clearSessionCache($session);
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'session_revoked',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_id' => $session['user_uuid'],
                    'session_id' => $session['uuid'],
                    'ip_address' => $this->requestContext->getClientIp(),
                ]
            );

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
            if ($this->cacheEnabled) {
                foreach ($sessions as $session) {
                    $this->clearSessionCache($session);
                }
            }

            if ($this->useTransactions) {
                $this->connection->getPDO()->commit();
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'user_sessions_revoked',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_id' => $userUuid,
                    'sessions_count' => count($sessions),
                    'ip_address' => $this->requestContext->getClientIp(),
                ]
            );

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
                if ($this->cacheEnabled) {
                    foreach ($expiredSessions as $session) {
                        $this->clearSessionCache($session);
                    }
                }
            }

            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'expired_sessions_cleaned',
                AuditEvent::SEVERITY_INFO,
                [
                    'cleaned_count' => $cleanedCount,
                ]
            );
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
        if (!$this->cacheEnabled) {
            return true; // Can't be inconsistent if cache is disabled
        }

        try {
            // Get from database
            $dbSession = $this->getSessionFromDatabase($sessionIdentifier);

            // Get from cache
            $cacheKey = "session_refresh:{$sessionIdentifier}";
            $cachedData = CacheEngine::get($cacheKey);
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
        if ($this->cacheEnabled) {
            try {
                $start = microtime(true);
                CacheEngine::set('health_check', 'ok', 5);
                CacheEngine::get('health_check');
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
        $cacheHealthy = !$this->cacheEnabled || $health['cache']['status'] === 'healthy';

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
        CacheEngine::set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        CacheEngine::set($accessCacheKey, $canonicalKey, $this->cacheDefaultTtl);
        CacheEngine::set($refreshCacheKey, $canonicalKey, $refreshTtl);
    }

    private function updateSessionInCache(array $sessionData, array $newTokens): void
    {
        // Remove old cache entries
        CacheEngine::delete("session_token:{$sessionData['access_token']}");
        CacheEngine::delete("session_refresh:{$sessionData['refresh_token']}");

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
        CacheEngine::set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        CacheEngine::set("session_token:{$newTokens['access_token']}", $canonicalKey, $this->cacheDefaultTtl);
        CacheEngine::set("session_refresh:{$newTokens['refresh_token']}", $canonicalKey, $refreshTtl);
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
        CacheEngine::set($canonicalKey, $sessionDataJson, $maxTtl);

        // Store references with appropriate TTLs
        if ($accessToken) {
            CacheEngine::set("session_token:{$accessToken}", $canonicalKey, $this->cacheDefaultTtl);
        }

        if ($refreshToken) {
            CacheEngine::set("session_refresh:{$refreshToken}", $canonicalKey, $refreshTtl);
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
        $cachedValue = CacheEngine::get($key);

        if ($cachedValue === null) {
            return null;
        }

        // Check if this is a reference (starts with session_data:)
        if (is_string($cachedValue) && strpos($cachedValue, 'session_data:') === 0) {
            // This is a reference, resolve it
            return CacheEngine::get($cachedValue);
        }

        // This is the actual data
        return $cachedValue;
    }

    private function clearSessionCache(array $session): void
    {
        if (isset($session['access_token'])) {
            CacheEngine::delete("session_token:{$session['access_token']}");
        }

        if (isset($session['refresh_token'])) {
            CacheEngine::delete("session_refresh:{$session['refresh_token']}");
        }

        // Also clear the canonical session data
        if (isset($session['session_id'])) {
            CacheEngine::delete("session_data:{$session['session_id']}");
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
