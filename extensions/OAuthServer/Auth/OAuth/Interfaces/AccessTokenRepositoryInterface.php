<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Access Token Repository Interface
 *
 * Custom implementation of the OAuth2 Server AccessTokenRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface AccessTokenRepositoryInterface
{
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
    ): AccessTokenEntityInterface;

    /**
     * Persist a new access token to permanent storage
     *
     * @param AccessTokenEntityInterface $accessTokenEntity Access token to persist
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void;

    /**
     * Revoke an access token
     *
     * @param string $tokenId Access token ID to revoke
     */
    public function revokeAccessToken($tokenId): void;

    /**
     * Check if an access token has been revoked
     *
     * @param string $tokenId Access token ID to check
     * @return bool True if token was revoked
     */
    public function isAccessTokenRevoked($tokenId): bool;
}
