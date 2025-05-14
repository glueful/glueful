<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use JsonSerializable;

/**
 * Scope Entity
 *
 * Represents an OAuth scope
 */
class ScopeEntity implements ScopeEntityInterface, JsonSerializable
{
    /**
     * @var string Scope identifier
     */
    private string $identifier;

    /**
     * @var string Scope description
     */
    private string $description;

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Set the scope identifier
     *
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * Get the scope description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set the scope description
     *
     * @param string $description
     */
    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): string
    {
        return $this->getIdentifier();
    }
}
