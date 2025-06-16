<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;

/**
 * Session Query Builder
 *
 * Advanced query builder for filtering and selecting sessions based on complex criteria.
 * Provides a fluent interface for building sophisticated session queries.
 *
 * Features:
 * - Provider-based filtering
 * - User role and permission filtering
 * - Time-based queries
 * - Geographic and device filtering
 * - Chained query conditions
 * - Optimized batch operations
 *
 * @package Glueful\Auth
 */
class SessionQueryBuilder
{
    private string $managerClass;
    private array $conditions = [];
    private array $sorts = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(string $managerClass)
    {
        $this->managerClass = $managerClass;
    }

    /**
     * Filter by authentication provider
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return self
     */
    public function whereProvider(string $provider): self
    {
        $this->conditions[] = ['type' => 'provider', 'operator' => '=', 'value' => $provider];
        return $this;
    }

    /**
     * Filter by multiple providers
     *
     * @param array $providers Array of provider names
     * @return self
     */
    public function whereProviderIn(array $providers): self
    {
        $this->conditions[] = ['type' => 'provider', 'operator' => 'in', 'value' => $providers];
        return $this;
    }

    /**
     * Filter by user role
     *
     * @param string $role Role name
     * @return self
     */
    public function whereUserRole(string $role): self
    {
        $this->conditions[] = ['type' => 'user_role', 'operator' => '=', 'value' => $role];
        return $this;
    }

    /**
     * Filter by user having specific permission
     *
     * @param string $permission Permission name
     * @return self
     */
    public function whereUserHasPermission(string $permission): self
    {
        $this->conditions[] = ['type' => 'user_permission', 'operator' => 'has', 'value' => $permission];
        return $this;
    }

    /**
     * Filter by last activity time
     *
     * @param int $seconds Seconds since last activity
     * @return self
     */
    public function whereLastActivityOlderThan(int $seconds): self
    {
        $timestamp = time() - $seconds;
        $this->conditions[] = ['type' => 'last_activity', 'operator' => '<', 'value' => $timestamp];
        return $this;
    }

    /**
     * Filter by last activity within timeframe
     *
     * @param int $seconds Seconds since last activity
     * @return self
     */
    public function whereLastActivityWithin(int $seconds): self
    {
        $timestamp = time() - $seconds;
        $this->conditions[] = ['type' => 'last_activity', 'operator' => '>=', 'value' => $timestamp];
        return $this;
    }

    /**
     * Filter by session creation time
     *
     * @param int $fromTime Start timestamp
     * @param int $toTime End timestamp
     * @return self
     */
    public function whereCreatedBetween(int $fromTime, int $toTime): self
    {
        $this->conditions[] = ['type' => 'created_at', 'operator' => 'between', 'value' => [$fromTime, $toTime]];
        return $this;
    }

    /**
     * Filter by user UUID
     *
     * @param string $userUuid User UUID
     * @return self
     */
    public function whereUser(string $userUuid): self
    {
        $this->conditions[] = ['type' => 'user_uuid', 'operator' => '=', 'value' => $userUuid];
        return $this;
    }

    /**
     * Filter by multiple users
     *
     * @param array $userUuids Array of user UUIDs
     * @return self
     */
    public function whereUserIn(array $userUuids): self
    {
        $this->conditions[] = ['type' => 'user_uuid', 'operator' => 'in', 'value' => $userUuids];
        return $this;
    }

    /**
     * Filter by IP address
     *
     * @param string $ipAddress IP address
     * @return self
     */
    public function whereIpAddress(string $ipAddress): self
    {
        $this->conditions[] = ['type' => 'ip_address', 'operator' => '=', 'value' => $ipAddress];
        return $this;
    }

    /**
     * Filter by IP address pattern
     *
     * @param string $pattern IP pattern (e.g., "192.168.*")
     * @return self
     */
    public function whereIpAddressLike(string $pattern): self
    {
        $this->conditions[] = ['type' => 'ip_address', 'operator' => 'like', 'value' => $pattern];
        return $this;
    }

    /**
     * Filter by user agent pattern
     *
     * @param string $pattern User agent pattern
     * @return self
     */
    public function whereUserAgentLike(string $pattern): self
    {
        $this->conditions[] = ['type' => 'user_agent', 'operator' => 'like', 'value' => $pattern];
        return $this;
    }

    /**
     * Filter sessions where user has any of the specified roles
     *
     * @param array $roles Array of role names
     * @return self
     */
    public function whereUserHasAnyRole(array $roles): self
    {
        $this->conditions[] = ['type' => 'user_roles', 'operator' => 'intersects', 'value' => $roles];
        return $this;
    }

    /**
     * Filter sessions where user has all specified roles
     *
     * @param array $roles Array of role names
     * @return self
     */
    public function whereUserHasAllRoles(array $roles): self
    {
        $this->conditions[] = ['type' => 'user_roles', 'operator' => 'contains_all', 'value' => $roles];
        return $this;
    }

    /**
     * Add custom condition with callback
     *
     * @param callable $callback Custom filter function
     * @return self
     */
    public function where(callable $callback): self
    {
        $this->conditions[] = ['type' => 'custom', 'operator' => 'callback', 'value' => $callback];
        return $this;
    }

    /**
     * Add nested query conditions
     *
     * @param callable $callback Callback that receives a new query builder instance
     * @return self
     */
    public function whereSessions(callable $callback): self
    {
        $nestedQuery = new self($this->managerClass);
        $callback($nestedQuery);
        $this->conditions[] = ['type' => 'nested', 'operator' => 'and', 'value' => $nestedQuery->conditions];
        return $this;
    }

    /**
     * Add OR conditions
     *
     * @param callable $callback Callback for OR conditions
     * @return self
     */
    public function orWhere(callable $callback): self
    {
        $orQuery = new self($this->managerClass);
        $callback($orQuery);
        $this->conditions[] = ['type' => 'nested', 'operator' => 'or', 'value' => $orQuery->conditions];
        return $this;
    }

    /**
     * Sort by field
     *
     * @param string $field Field to sort by
     * @param string $direction Sort direction (asc/desc)
     * @return self
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->sorts[] = ['field' => $field, 'direction' => strtolower($direction)];
        return $this;
    }

    /**
     * Limit results
     *
     * @param int $limit Maximum number of results
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set offset
     *
     * @param int $offset Number of results to skip
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Get paginated results
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Results per page
     * @return self
     */
    public function paginate(int $page, int $perPage): self
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    /**
     * Execute query and get results
     *
     * @return array Array of matching sessions
     */
    public function get(): array
    {
        // Get all active sessions from various sources
        $allSessions = $this->getAllSessions();

        // Apply filters
        $filteredSessions = $this->applyFilters($allSessions);

        // Apply sorting
        $sortedSessions = $this->applySorting($filteredSessions);

        // Apply pagination
        return $this->applyPagination($sortedSessions);
    }

    /**
     * Get count of matching sessions
     *
     * @return int Number of matching sessions
     */
    public function count(): int
    {
        $allSessions = $this->getAllSessions();
        $filteredSessions = $this->applyFilters($allSessions);
        return count($filteredSessions);
    }

    /**
     * Get first matching session
     *
     * @return array|null First matching session or null
     */
    public function first(): ?array
    {
        $results = $this->limit(1)->get();
        return $results[0] ?? null;
    }

    /**
     * Check if any sessions match the criteria
     *
     * @return bool True if sessions exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get all sessions from cache indexes
     *
     * @return array All active sessions
     */
    private function getAllSessions(): array
    {
        $sessions = [];

        // Get sessions from all provider indexes
        $providers = ['jwt', 'apikey', 'oauth', 'saml']; // Common providers

        foreach ($providers as $provider) {
            try {
                $providerSessions = call_user_func([$this->managerClass, 'getSessionsByProviderForQuery'], $provider);
                $sessions = array_merge($sessions, $providerSessions);
            } catch (\Exception $e) {
                // Provider might not exist, continue
                continue;
            }
        }

        // Remove duplicates based on session ID
        $uniqueSessions = [];
        foreach ($sessions as $session) {
            if (isset($session['id'])) {
                $uniqueSessions[$session['id']] = $session;
            }
        }

        return array_values($uniqueSessions);
    }

    /**
     * Apply filters to sessions array
     *
     * @param array $sessions Input sessions
     * @return array Filtered sessions
     */
    private function applyFilters(array $sessions): array
    {
        if (empty($this->conditions)) {
            return $sessions;
        }

        return array_filter($sessions, function ($session) {
            return $this->evaluateConditions($session, $this->conditions);
        });
    }

    /**
     * Evaluate conditions against a session
     *
     * @param array $session Session data
     * @param array $conditions Conditions to evaluate
     * @param string $logic Logic operator (and/or)
     * @return bool Whether session matches conditions
     */
    private function evaluateConditions(array $session, array $conditions, string $logic = 'and'): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $results = [];

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($session, $condition);
            $results[] = $result;

            // Short-circuit for AND logic
            if ($logic === 'and' && !$result) {
                return false;
            }

            // Short-circuit for OR logic
            if ($logic === 'or' && $result) {
                return true;
            }
        }

        return $logic === 'and' ? !in_array(false, $results) : in_array(true, $results);
    }

    /**
     * Evaluate single condition
     *
     * @param array $session Session data
     * @param array $condition Condition to evaluate
     * @return bool Whether condition matches
     */
    private function evaluateCondition(array $session, array $condition): bool
    {
        $type = $condition['type'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        switch ($type) {
            case 'provider':
                return $this->evaluateProviderCondition($session, $operator, $value);

            case 'user_role':
                return $this->evaluateUserRoleCondition($session, $operator, $value);

            case 'user_permission':
                return $this->evaluateUserPermissionCondition($session, $operator, $value);

            case 'user_roles':
                return $this->evaluateUserRolesCondition($session, $operator, $value);

            case 'last_activity':
                return $this->evaluateLastActivityCondition($session, $operator, $value);

            case 'created_at':
                return $this->evaluateCreatedAtCondition($session, $operator, $value);

            case 'user_uuid':
                return $this->evaluateUserUuidCondition($session, $operator, $value);

            case 'ip_address':
                return $this->evaluateIpAddressCondition($session, $operator, $value);

            case 'user_agent':
                return $this->evaluateUserAgentCondition($session, $operator, $value);

            case 'custom':
                return $this->evaluateCustomCondition($session, $value);

            case 'nested':
                return $this->evaluateConditions($session, $value, $operator);

            default:
                return true;
        }
    }

    /**
     * Evaluate provider condition
     */
    private function evaluateProviderCondition(array $session, string $operator, $value): bool
    {
        $sessionProvider = $session['provider'] ?? 'jwt';

        switch ($operator) {
            case '=':
                return $sessionProvider === $value;
            case 'in':
                return in_array($sessionProvider, $value);
            default:
                return false;
        }
    }

    /**
     * Evaluate user role condition
     */
    private function evaluateUserRoleCondition(array $session, string $operator, $value): bool
    {
        $userRoles = $session['user']['roles'] ?? [];

        switch ($operator) {
            case '=':
                return in_array($value, array_column($userRoles, 'name'));
            default:
                return false;
        }
    }

    /**
     * Evaluate user permission condition
     */
    private function evaluateUserPermissionCondition(array $session, string $operator, $value): bool
    {
        $userPermissions = $session['user']['permissions'] ?? [];

        switch ($operator) {
            case 'has':
                // Check if user has specific permission
                foreach ($userPermissions as $resource => $actions) {
                    if (is_array($actions) && in_array($value, $actions)) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Evaluate user roles condition
     */
    private function evaluateUserRolesCondition(array $session, string $operator, $value): bool
    {
        $userRoles = array_column($session['user']['roles'] ?? [], 'name');

        switch ($operator) {
            case 'intersects':
                return !empty(array_intersect($userRoles, $value));
            case 'contains_all':
                return empty(array_diff($value, $userRoles));
            default:
                return false;
        }
    }

    /**
     * Evaluate last activity condition
     */
    private function evaluateLastActivityCondition(array $session, string $operator, $value): bool
    {
        $lastActivity = $session['last_activity'] ?? 0;

        switch ($operator) {
            case '<':
                return $lastActivity < $value;
            case '>=':
                return $lastActivity >= $value;
            case '=':
                return $lastActivity === $value;
            default:
                return false;
        }
    }

    /**
     * Evaluate created at condition
     */
    private function evaluateCreatedAtCondition(array $session, string $operator, $value): bool
    {
        $createdAt = $session['created_at'] ?? 0;

        switch ($operator) {
            case 'between':
                return $createdAt >= $value[0] && $createdAt <= $value[1];
            default:
                return false;
        }
    }

    /**
     * Evaluate user UUID condition
     */
    private function evaluateUserUuidCondition(array $session, string $operator, $value): bool
    {
        $userUuid = $session['user']['uuid'] ?? null;

        switch ($operator) {
            case '=':
                return $userUuid === $value;
            case 'in':
                return in_array($userUuid, $value);
            default:
                return false;
        }
    }

    /**
     * Evaluate IP address condition
     */
    private function evaluateIpAddressCondition(array $session, string $operator, $value): bool
    {
        // IP address might be stored in session metadata or extracted from request
        $ipAddress = $session['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

        if (!$ipAddress) {
            return false;
        }

        switch ($operator) {
            case '=':
                return $ipAddress === $value;
            case 'like':
                return fnmatch($value, $ipAddress);
            default:
                return false;
        }
    }

    /**
     * Evaluate user agent condition
     */
    private function evaluateUserAgentCondition(array $session, string $operator, $value): bool
    {
        $userAgent = $session['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (!$userAgent) {
            return false;
        }

        switch ($operator) {
            case 'like':
                return stripos($userAgent, $value) !== false;
            default:
                return false;
        }
    }

    /**
     * Evaluate custom condition
     */
    private function evaluateCustomCondition(array $session, callable $callback): bool
    {
        try {
            return (bool) $callback($session);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Apply sorting to sessions
     *
     * @param array $sessions Sessions to sort
     * @return array Sorted sessions
     */
    private function applySorting(array $sessions): array
    {
        if (empty($this->sorts)) {
            return $sessions;
        }

        usort($sessions, function ($a, $b) {
            foreach ($this->sorts as $sort) {
                $field = $sort['field'];
                $direction = $sort['direction'];

                $valueA = $this->getSessionValue($a, $field);
                $valueB = $this->getSessionValue($b, $field);

                $comparison = $this->compareValues($valueA, $valueB);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $sessions;
    }

    /**
     * Get value from session by field path
     */
    private function getSessionValue(array $session, string $field)
    {
        $keys = explode('.', $field);
        $value = $session;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Compare two values for sorting
     */
    private function compareValues($a, $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strcmp((string)$a, (string)$b);
    }

    /**
     * Apply pagination to sessions
     *
     * @param array $sessions Sessions to paginate
     * @return array Paginated sessions
     */
    private function applyPagination(array $sessions): array
    {
        if ($this->offset !== null) {
            $sessions = array_slice($sessions, $this->offset);
        }

        if ($this->limit !== null) {
            $sessions = array_slice($sessions, 0, $this->limit);
        }

        return $sessions;
    }

    /**
     * Get SQL-like query representation (for debugging)
     *
     * @return string Query string representation
     */
    public function toSql(): string
    {
        $parts = [];

        if (!empty($this->conditions)) {
            $parts[] = 'WHERE ' . $this->conditionsToString($this->conditions);
        }

        if (!empty($this->sorts)) {
            $sortParts = [];
            foreach ($this->sorts as $sort) {
                $sortParts[] = $sort['field'] . ' ' . strtoupper($sort['direction']);
            }
            $parts[] = 'ORDER BY ' . implode(', ', $sortParts);
        }

        if ($this->limit !== null) {
            $parts[] = 'LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $parts[] = 'OFFSET ' . $this->offset;
        }

        return 'SELECT * FROM sessions ' . implode(' ', $parts);
    }

    /**
     * Convert conditions to string representation
     */
    private function conditionsToString(array $conditions): string
    {
        $parts = [];

        foreach ($conditions as $condition) {
            if ($condition['type'] === 'nested') {
                $parts[] = '(' . $this->conditionsToString($condition['value']) . ')';
            } else {
                $value = is_array($condition['value'])
                    ? '[' . implode(',', $condition['value']) . ']'
                    : $condition['value'];
                $parts[] = $condition['type'] . ' ' . $condition['operator'] . ' ' . $value;
            }
        }

        return implode(' AND ', $parts);
    }
}
