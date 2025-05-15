<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
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
     * Get a scope entity by the identifier.
     *
     * @param string $identifier The scope identifier
     *
     * @return ScopeEntityInterface|null
     */
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        // Check if scope exists in your database or in a predefined list
        $availableScopes = [
            'read' => 'Read access to user resources',
            'write' => 'Write access to user resources',
            'profile' => 'Access to user profile',
            'email' => 'Access to user email',
            // Add more scopes as needed
        ];

        if (!array_key_exists($identifier, $availableScopes)) {
            return null; // Scope not found
        }

        // Create and return scope entity
        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);
        $scope->setDescription($availableScopes[$identifier]);

        return $scope;
    }

    /**
     * Filter out scopes that are not valid for the current client and user
     *
     * @param ScopeEntityInterface[] $scopes The scopes requested
     * @param string $grantType The grant type used in the request
     * @param ClientEntityInterface $clientEntity The client making the request
     * @param string|null $userIdentifier The user identifier for user-specific scopes
     * @return ScopeEntityInterface[] The filtered scopes
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
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

        // If no scopes are allowed or requested, provide default scope
        if (empty($filteredScopes)) {
            // Add default 'read' scope if appropriate
            $defaultScope = $this->getScopeEntityByIdentifier('read');
            if ($defaultScope !== null) {
                $filteredScopes[] = $defaultScope;
            }
        }

        return $filteredScopes;
    }
}
