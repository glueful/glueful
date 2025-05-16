<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Auth Code Entity
 *
 * Represents an OAuth authorization code
 */
class AuthCodeEntity implements AuthCodeEntityInterface
{
    /**
     * @var string Authorization code identifier
     */
    private string $identifier;

    /**
     * @var \DateTimeImmutable Authorization code expiry date/time
     */
    private \DateTimeImmutable $expiryDateTime;

    /**
     * @var string|int|null User identifier
     */
    private $userIdentifier = null;

    /**
     * @var ClientEntityInterface Client the authorization code was issued to
     */
    private ClientEntityInterface $client;

    /**
     * @var ScopeEntityInterface[] Scopes associated with the authorization code
     */
    private array $scopes = [];

    /**
     * @var string|null Redirect URI
     */
    private ?string $redirectUri = null;

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

    /**
     * {@inheritdoc}
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    /**
     * {@inheritdoc}
     */
    public function setRedirectUri(string $uri): void
    {
        $this->redirectUri = $uri;
    }
}
