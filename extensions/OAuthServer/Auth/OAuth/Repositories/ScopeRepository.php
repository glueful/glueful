<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\ScopeEntity;

/**
 * Scope Repository
 *
 * Handles retrieving and validating OAuth scopes
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * @var QueryBuilder Database query builder instance
     */
    private QueryBuilder $queryBuilder;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize database connection and query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get a scope entity by the scope identifier
     *
     * @param string $scopeIdentifier The scope identifier
     * @return ScopeEntity|null
     */
    public function getScopeEntityByIdentifier($scopeIdentifier)
    {
        // Query for the scope
        $scopes = $this->queryBuilder->select('oauth_scopes', ['id', 'description'])
            ->where(['id' => $scopeIdentifier])
            ->limit(1)
            ->get();

        if (empty($scopes)) {
            return null;
        }

        $scope = $scopes[0];

        $scopeEntity = new ScopeEntity();
        $scopeEntity->setIdentifier($scope['id']);
        $scopeEntity->setDescription($scope['description']);

        return $scopeEntity;
    }

    /**
     * Filter out scopes that are not valid for the current client and user
     *
     * @param array $scopes The scopes requested
     * @param string $grantType The grant type used in the request
     * @param ClientEntityInterface $clientEntity The client making the request
     * @param string|null $userIdentifier The user identifier for user-specific scopes
     * @return array The filtered scopes
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ) {
        // Retrieve allowed scopes for this client
        $allowedScopes = $this->queryBuilder->select('oauth_client_scopes', ['scope_id'])
            ->where(['client_id' => $clientEntity->getIdentifier()])
            ->get();

        $allowedScopeIds = [];
        foreach ($allowedScopes as $allowedScope) {
            $allowedScopeIds[] = $allowedScope['scope_id'];
        }

        // Filter scopes to keep only those allowed for this client
        $filteredScopes = [];
        foreach ($scopes as $scope) {
            $scopeId = $scope->getIdentifier();
            if (in_array($scopeId, $allowedScopeIds)) {
                $filteredScopes[] = $scope;
            }
        }

        return $filteredScopes;
    }
}
