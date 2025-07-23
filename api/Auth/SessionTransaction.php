<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheStore;
use Glueful\Helpers\CacheHelper;

/**
 * Session Transaction Manager
 *
 * Provides atomic transaction support for bulk session operations.
 * Enables rollback capabilities for failed operations.
 *
 * Features:
 * - Transaction state management
 * - Operation recording and rollback
 * - Error tracking and reporting
 * - Audit logging integration
 * - Memory-efficient batch operations
 *
 * @package Glueful\Auth
 */
class SessionTransaction
{
    private array $operations = [];
    private array $rollbackOperations = [];
    private array $errors = [];
    private bool $active = false;
    private bool $committed = false;
    private bool $rolledBack = false;
    private string $transactionId;
    private float $startTime;
    private SessionCacheManager $sessionCacheManager;
    private CacheStore $cache;

    public function __construct(?SessionCacheManager $sessionCacheManager = null, ?CacheStore $cache = null)
    {
        $this->transactionId = uniqid('session_tx_', true);
        $this->startTime = microtime(true);
        $this->sessionCacheManager = $sessionCacheManager ?? container()->get(SessionCacheManager::class);
        $this->cache = $cache ?? CacheHelper::createCacheInstance();
        if ($this->cache === null) {
            throw new \RuntimeException(
                'CacheStore is required for SessionTransaction: Unable to create cache instance.'
            );
        }
    }

    /**
     * Begin transaction
     *
     * @return void
     */
    public function begin(): void
    {
        if ($this->active) {
            throw new \RuntimeException('Transaction already active');
        }

        $this->active = true;
        $this->operations = [];
        $this->rollbackOperations = [];
        $this->errors = [];
        $this->committed = false;
        $this->rolledBack = false;
    }

    /**
     * Commit transaction
     *
     * @return bool Success status
     */
    public function commit(): bool
    {
        if (!$this->active) {
            throw new \RuntimeException('No active transaction to commit');
        }

        if ($this->committed || $this->rolledBack) {
            throw new \RuntimeException('Transaction already finalized');
        }

        $this->committed = true;
        $this->active = false;

        return true;
    }

    /**
     * Rollback transaction
     *
     * @return bool Success status
     */
    public function rollback(): bool
    {
        if (!$this->active && !$this->committed) {
            throw new \RuntimeException('No active transaction to rollback');
        }

        if ($this->rolledBack) {
            throw new \RuntimeException('Transaction already rolled back');
        }

        $rollbackErrors = [];

        try {
            // Execute rollback operations in reverse order
            foreach (array_reverse($this->rollbackOperations) as $rollbackOp) {
                try {
                    $this->executeRollbackOperation($rollbackOp);
                } catch (\Exception $e) {
                    $rollbackErrors[] = $e->getMessage();
                }
            }

            $this->rolledBack = true;
            $this->active = false;


            return empty($rollbackErrors);
        } catch (\Exception $e) {
            $this->errors[] = 'Rollback failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Invalidate sessions matching criteria
     *
     * @param array $criteria Session selection criteria
     * @return int Number of sessions invalidated
     */
    public function invalidateSessionsWhere(array $criteria): int
    {
        $this->ensureActive();

        try {
            // Find matching sessions
            $query = $this->sessionCacheManager->sessionQuery();

            foreach ($criteria as $field => $value) {
                switch ($field) {
                    case 'provider':
                        $query->whereProvider($value);
                        break;
                    case 'idle_time':
                        if (is_string($value) && strpos($value, '>') === 0) {
                            $seconds = (int) substr($value, 1);
                            $query->whereLastActivityOlderThan($seconds);
                        }
                        break;
                    case 'user_role':
                        $query->whereUserRole($value);
                        break;
                    case 'user_uuid':
                        $query->whereUser($value);
                        break;
                    default:
                        $query->where(function ($session) use ($field, $value) {
                            return ($session[$field] ?? null) === $value;
                        });
                }
            }

            $sessions = $query->get();
            $invalidatedCount = 0;

            // Store rollback information before invalidation
            $rollbackData = [];

            foreach ($sessions as $session) {
                if (isset($session['token'])) {
                    // Store session data for potential rollback
                    $rollbackData[] = [
                        'type' => 'restore_session',
                        'session_data' => $session
                    ];

                    // Invalidate session
                    if ($this->sessionCacheManager->destroySession($session['token'])) {
                        $invalidatedCount++;
                    }
                }
            }

            // Record operation
            $this->operations[] = [
                'type' => 'invalidate_sessions',
                'criteria' => $criteria,
                'count' => $invalidatedCount
            ];

            // Store rollback operations
            foreach ($rollbackData as $rollback) {
                $this->rollbackOperations[] = $rollback;
            }

            return $invalidatedCount;
        } catch (\Exception $e) {
            $this->errors[] = "Failed to invalidate sessions: " . $e->getMessage();
            return 0;
        }
    }

    /**
     * Update sessions matching criteria
     *
     * @param array $criteria Session selection criteria
     * @param array $updates Updates to apply
     * @return int Number of sessions updated
     */
    public function updateSessionsWhere(array $criteria, array $updates): int
    {
        $this->ensureActive();

        try {
            // Find matching sessions
            $query = $this->sessionCacheManager->sessionQuery();

            foreach ($criteria as $field => $value) {
                switch ($field) {
                    case 'user_role':
                        $query->whereUserRole($value);
                        break;
                    case 'provider':
                        $query->whereProvider($value);
                        break;
                    default:
                        $query->where(function ($session) use ($field, $value) {
                            return ($session[$field] ?? null) === $value;
                        });
                }
            }

            $sessions = $query->get();
            $updatedCount = 0;

            foreach ($sessions as $session) {
                // Store original data for rollback
                $this->rollbackOperations[] = [
                    'type' => 'restore_session',
                    'session_data' => $session
                ];

                // Apply updates
                foreach ($updates as $key => $value) {
                    $session[$key] = $value;
                }

                // Update session in cache
                $sessionId = $session['id'];
                $ttl = $this->sessionCacheManager->getProviderTtlPublic($session['provider'] ?? 'jwt');

                if ($this->cache->set('session:' . $sessionId, $session, $ttl)) {
                    $updatedCount++;
                }
            }

            // Record operation
            $this->operations[] = [
                'type' => 'update_sessions',
                'criteria' => $criteria,
                'updates' => $updates,
                'count' => $updatedCount
            ];

            return $updatedCount;
        } catch (\Exception $e) {
            $this->errors[] = "Failed to update sessions: " . $e->getMessage();
            return 0;
        }
    }

    /**
     * Create bulk sessions
     *
     * @param array $sessionsData Array of session data
     * @return array Array of created session IDs
     */
    public function createSessions(array $sessionsData): array
    {
        $this->ensureActive();

        $createdSessions = [];

        try {
            foreach ($sessionsData as $sessionData) {
                $userData = $sessionData['user'] ?? [];
                if (!isset($sessionData['token'])) {
                    $tokenPair = TokenManager::generateTokenPair($userData);
                    $token = $tokenPair['access_token'];
                } else {
                    $token = $sessionData['token'];
                }
                $provider = $sessionData['provider'] ?? 'jwt';
                $ttl = $sessionData['ttl'] ?? null;

                if ($this->sessionCacheManager->storeSession($userData, $token, $provider, $ttl)) {
                    $sessionId = TokenManager::getSessionIdFromToken($token);
                    $createdSessions[] = $sessionId;

                    // Store rollback operation
                    $this->rollbackOperations[] = [
                        'type' => 'delete_session',
                        'session_id' => $sessionId,
                        'token' => $token
                    ];
                }
            }

            // Record operation
            $this->operations[] = [
                'type' => 'create_sessions',
                'count' => count($createdSessions)
            ];

            return $createdSessions;
        } catch (\Exception $e) {
            $this->errors[] = "Failed to create sessions: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Migrate sessions between providers
     *
     * @param string $fromProvider Source provider
     * @param string $toProvider Target provider
     * @return int Number of sessions migrated
     */
    public function migrateSessions(string $fromProvider, string $toProvider): int
    {
        $this->ensureActive();

        try {
            $sessions = $this->sessionCacheManager->getSessionsByProvider($fromProvider);
            $migratedCount = 0;

            foreach ($sessions as $session) {
                // Store original data for rollback
                $this->rollbackOperations[] = [
                    'type' => 'restore_session',
                    'session_data' => $session
                ];

                // Update provider
                $session['provider'] = $toProvider;

                // Update session in cache
                $sessionId = $session['id'];
                $newTtl = $this->sessionCacheManager->getProviderTtlPublic($toProvider);

                if ($this->cache->set('session:' . $sessionId, $session, $newTtl)) {
                    // Update indexes
                    $this->sessionCacheManager->removeSessionFromProviderIndexPublic($fromProvider, $sessionId);
                    $this->sessionCacheManager->indexSessionByProviderPublic($toProvider, $sessionId, $newTtl);
                    $migratedCount++;
                }
            }

            // Record operation
            $this->operations[] = [
                'type' => 'migrate_sessions',
                'from_provider' => $fromProvider,
                'to_provider' => $toProvider,
                'count' => $migratedCount
            ];

            return $migratedCount;
        } catch (\Exception $e) {
            $this->errors[] = "Failed to migrate sessions: " . $e->getMessage();
            return 0;
        }
    }


    /**
     * Check if transaction has errors
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get all errors
     *
     * @return array Array of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get transaction statistics
     *
     * @return array Transaction statistics
     */
    public function getStats(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'operations_count' => count($this->operations),
            'errors_count' => count($this->errors),
            'active' => $this->active,
            'committed' => $this->committed,
            'rolled_back' => $this->rolledBack,
            'duration_seconds' => microtime(true) - $this->startTime,
            'operations' => $this->operations
        ];
    }

    /**
     * Ensure transaction is active
     *
     * @throws \RuntimeException If transaction is not active
     */
    private function ensureActive(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('No active transaction');
        }

        if ($this->committed || $this->rolledBack) {
            throw new \RuntimeException('Transaction already finalized');
        }
    }

    /**
     * Execute rollback operation
     *
     * @param array $operation Rollback operation
     * @return void
     */
    private function executeRollbackOperation(array $operation): void
    {
        switch ($operation['type']) {
            case 'restore_session':
                $this->restoreSession($operation['session_data']);
                break;

            case 'delete_session':
                $this->deleteSession($operation['token']);
                break;

            default:
                throw new \RuntimeException("Unknown rollback operation: {$operation['type']}");
        }
    }

    /**
     * Restore session data
     *
     * @param array $sessionData Session data to restore
     * @return void
     */
    private function restoreSession(array $sessionData): void
    {
        $sessionId = $sessionData['id'];
        $ttl = $this->sessionCacheManager->getProviderTtlPublic($sessionData['provider'] ?? 'jwt');

        $this->cache->set('session:' . $sessionId, $sessionData, $ttl);

        // Restore indexes
        if (isset($sessionData['provider'])) {
            $this->sessionCacheManager->indexSessionByProviderPublic($sessionData['provider'], $sessionId, $ttl);
        }

        if (isset($sessionData['user']['uuid'])) {
            // Note: indexSessionByUser is private, would need to be exposed or reimplemented
            // For now, we'll handle this in a future enhancement
        }
    }

    /**
     * Delete session (for rollback of creation)
     *
     * @param string $token Session token
     * @return void
     */
    private function deleteSession(string $token): void
    {
        $this->sessionCacheManager->destroySession($token);
    }
}
