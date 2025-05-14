<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\RefreshTokenEntity;

/**
 * Refresh Token Repository
 *
 * Handles storing and retrieving OAuth refresh tokens
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
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
     * Create a new refresh token
     *
     * @return RefreshTokenEntityInterface
     */
    public function getNewRefreshToken()
    {
        return new RefreshTokenEntity();
    }

    /**
     * Persist a new refresh token to storage
     *
     * @param RefreshTokenEntityInterface $refreshTokenEntity
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        $this->queryBuilder->insert('oauth_refresh_tokens', [
            'id' => $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Revoke a refresh token
     *
     * @param string $tokenId
     */
    public function revokeRefreshToken($tokenId)
    {
        $this->queryBuilder->update(
            'oauth_refresh_tokens',
            ['revoked' => true],
            ['id' => $tokenId]
        );
    }

    /**
     * Check if a refresh token has been revoked
     *
     * @param string $tokenId
     * @return bool
     */
    public function isRefreshTokenRevoked($tokenId)
    {
        $token = $this->queryBuilder->select('oauth_refresh_tokens', ['revoked'])
            ->where(['id' => $tokenId])
            ->limit(1)
            ->get();

        if (empty($token)) {
            return true; // Token not found, consider it revoked
        }

        return (bool) $token[0]['revoked'];
    }
}
