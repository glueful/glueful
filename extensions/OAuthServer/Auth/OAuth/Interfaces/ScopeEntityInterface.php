<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Entities;

/**
 * Scope Entity Interface
 *
 * Custom implementation of the OAuth2 Server ScopeEntityInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface ScopeEntityInterface
{
    /**
     * Get the scope identifier
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Serialize the scope for JSON encoding
     *
     * @return string
     */
    public function jsonSerialize(): string;
}
