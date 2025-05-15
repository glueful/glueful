<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Access Token Entity
 *
 * Represents an OAuth access token with its related data
 */
class AccessTokenEntity implements AccessTokenEntityInterface
{
    /**
     * @var string Token identifier
     */
    private string $identifier;

    /**
     * @var \DateTimeImmutable Token expiry date/time
     */
    private \DateTimeImmutable $expiryDateTime;

    /**
     * @var string|int|null User identifier
     */
    private $userIdentifier = null;

    /**
     * @var ClientEntityInterface Client the token was issued to
     */
    private ClientEntityInterface $client;

    /**
     * @var ScopeEntityInterface[] Scopes associated with the token
     */
    private array $scopes = [];

    /**
     * @var \League\OAuth2\Server\CryptKey|null Private key
     */
    private $privateKey;

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
    public function setIdentifier($identifier): void
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
    public function setUserIdentifier($identifier): void
    {
        $this->userIdentifier = $identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientEntityInterface $client): void
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function getClient(): ClientEntityInterface
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function addScope(ScopeEntityInterface $scope): void
    {
        $this->scopes[] = $scope;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * {@inheritdoc}
     */
    public function setPrivateKey($privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    /**
     * {@inheritdoc}
     */
    public function toString(): string
    {
        return (string) $this->identifier;
    }
}
