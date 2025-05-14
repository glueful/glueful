<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Entities;

/**
 * Access Token Entity Interface
 *
 * Custom implementation of the OAuth2 Server AccessTokenEntityInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface AccessTokenEntityInterface
{
    /**
     * Get the token's identifier
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Set the token's identifier
     *
     * @param string $identifier
     */
    public function setIdentifier($identifier): void;

    /**
     * Get the token's expiry date/time
     *
     * @return \DateTimeImmutable
     */
    public function getExpiryDateTime(): \DateTimeImmutable;

    /**
     * Set the date/time when the token expires
     *
     * @param \DateTimeImmutable $dateTime
     */
    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void;

    /**
     * Set the user identifier of the token
     *
     * @param string|int|null $identifier The user identifier
     */
    public function setUserIdentifier($identifier): void;

    /**
     * Get the user identifier of the token
     *
     * @return string|int|null
     */
    public function getUserIdentifier();

    /**
     * Set the client that the token was issued to
     *
     * @param ClientEntityInterface $client
     */
    public function setClient(ClientEntityInterface $client): void;

    /**
     * Get the client that the token was issued to
     *
     * @return ClientEntityInterface
     */
    public function getClient(): ClientEntityInterface;

    /**
     * Add a scope to the token
     *
     * @param ScopeEntityInterface $scope
     */
    public function addScope(ScopeEntityInterface $scope): void;

    /**
     * Get all scopes associated with the token
     *
     * @return ScopeEntityInterface[]
     */
    public function getScopes(): array;
}
