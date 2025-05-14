<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

/**
 * Refresh Token Repository Interface
 *
 * Custom implementation of the OAuth2 Server RefreshTokenRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface RefreshTokenRepositoryInterface
{
    /**
     * Create a new refresh token
     *
     * @return RefreshTokenEntityInterface
     */
    public function getNewRefreshToken();

    /**
     * Persist a new refresh token to storage
     *
     * @param RefreshTokenEntityInterface $refreshTokenEntity
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity);

    /**
     * Revoke a refresh token
     *
     * @param string $tokenId
     */
    public function revokeRefreshToken($tokenId);

    /**
     * Check if a refresh token has been revoked
     *
     * @param string $tokenId
     * @return bool
     */
    public function isRefreshTokenRevoked($tokenId);
}
