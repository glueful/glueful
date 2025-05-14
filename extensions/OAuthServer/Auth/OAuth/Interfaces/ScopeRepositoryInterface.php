<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Scope Repository Interface
 *
 * Custom implementation of the OAuth2 Server ScopeRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface ScopeRepositoryInterface
{
    /**
     * Get a scope entity by the scope identifier
     *
     * @param string $scopeIdentifier The scope identifier
     * @return ScopeEntityInterface|null
     */
    public function getScopeEntityByIdentifier($scopeIdentifier);

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
    );
}
