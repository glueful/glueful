<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Entities;

/**
 * Client Entity Interface
 *
 * Custom implementation of the OAuth2 Server ClientEntityInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface ClientEntityInterface
{
    /**
     * Get the client's identifier
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Get the client's name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the client's redirect URI
     *
     * @return string|string[]|null
     */
    public function getRedirectUri();

    /**
     * Check if the client is confidential
     *
     * @return bool
     */
    public function isConfidential(): bool;
}
