<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

/**
 * Refresh Token Entity
 *
 * Represents an OAuth refresh token
 */
class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    /**
     * @var string Refresh token identifier
     */
    private string $identifier;

    /**
     * @var \DateTimeImmutable Refresh token expiry date/time
     */
    private \DateTimeImmutable $expiryDateTime;

    /**
     * @var AccessTokenEntityInterface Associated access token
     */
    private AccessTokenEntityInterface $accessToken;

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiryDateTime(): \DateTimeImmutable
    {
        return $this->expiryDateTime;
    }

    /**
     * {@inheritdoc}
     */
    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void
    {
        $this->expiryDateTime = $dateTime;
    }

    /**
     * {@inheritdoc}
     */
    public function setAccessToken(AccessTokenEntityInterface $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): AccessTokenEntityInterface
    {
        return $this->accessToken;
    }
}
