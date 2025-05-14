<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Entities;

/**
 * Refresh Token Entity Interface
 *
 * Custom implementation of the OAuth2 Server RefreshTokenEntityInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface RefreshTokenEntityInterface
{
    /**
     * Get the refresh token's identifier
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Set the refresh token's identifier
     *
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void;

    /**
     * Get the refresh token's expiry date/time
     *
     * @return \DateTimeImmutable
     */
    public function getExpiryDateTime(): \DateTimeImmutable;

    /**
     * Set the date/time when the refresh token expires
     *
     * @param \DateTimeImmutable $dateTime
     */
    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void;

    /**
     * Set the access token that the refresh token was associated with
     *
     * @param AccessTokenEntityInterface $accessToken
     */
    public function setAccessToken(AccessTokenEntityInterface $accessToken): void;

    /**
     * Get the access token that the refresh token was associated with
     *
     * @return AccessTokenEntityInterface
     */
    public function getAccessToken(): AccessTokenEntityInterface;
}
