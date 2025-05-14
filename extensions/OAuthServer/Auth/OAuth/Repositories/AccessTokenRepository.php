<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\AccessTokenEntity;

/**
 * Access Token Repository
 *
 * Handles storing and retrieving OAuth access tokens
 */
class AccessTokenRepository implements AccessTokenRepositoryInterface
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
     * Create a new access token
     *
     * @param ClientEntityInterface $clientEntity Client entity that the token was issued to
     * @param array $scopes Scopes the token was issued with
     * @param mixed $userIdentifier User identifier the token was issued for
     * @return AccessTokenEntityInterface
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        $userIdentifier = null
    ): AccessTokenEntityInterface {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient($clientEntity);

        if ($userIdentifier !== null) {
            $accessToken->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    /**
     * Persist a new access token to permanent storage
     *
     * @param AccessTokenEntityInterface $accessTokenEntity Access token to persist
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        // Extract scope strings from scope entities
        $scopes = [];
        foreach ($accessTokenEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        // Store token in database
        $this->queryBuilder->insert('oauth_access_tokens', [
            'id' => $accessTokenEntity->getIdentifier(),
            'user_id' => $accessTokenEntity->getUserIdentifier(),
            'client_id' => $accessTokenEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Revoke an access token
     *
     * @param string $tokenId Access token ID to revoke
     */
    public function revokeAccessToken($tokenId): void
    {
        $this->queryBuilder->update(
            'oauth_access_tokens',
            ['revoked' => true],
            ['id' => $tokenId]
        );
    }

    /**
     * Check if an access token has been revoked
     *
     * @param string $tokenId Access token ID to check
     * @return bool True if token was revoked
     */
    public function isAccessTokenRevoked($tokenId): bool
    {
        $token = $this->queryBuilder->select('oauth_access_tokens', ['revoked'])
            ->where(['id' => $tokenId])
            ->limit(1)
            ->get();

        if (empty($token)) {
            return true; // Token not found, consider it revoked
        }

        return (bool) $token[0]['revoked'];
    }
}
