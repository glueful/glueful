<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Client Entity
 *
 * Represents an OAuth client application
 */
class ClientEntity implements ClientEntityInterface
{
    /**
     * @var string Client identifier
     */
    private string $identifier;

    /**
     * @var string Client name
     */
    private string $name;

    /**
     * @var string|string[]|null Redirect URI(s)
     */
    private $redirectUri;

    /**
     * @var bool Whether this client is confidential
     */
    private bool $isConfidential;

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Set the client identifier
     *
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the client name
     *
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): array|string
    {
        return $this->redirectUri;
    }

    /**
     * Set the client redirect URI(s)
     *
     * @param string|string[]|null $redirectUri
     */
    public function setRedirectUri($redirectUri): void
    {
        $this->redirectUri = $redirectUri;
    }

    /**
     * {@inheritdoc}
     */
    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }

    /**
     * Set whether the client is confidential
     *
     * @param bool $isConfidential
     */
    public function setConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
    }
}
