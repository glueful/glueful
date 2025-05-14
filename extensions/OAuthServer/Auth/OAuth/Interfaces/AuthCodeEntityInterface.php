<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Entities;

/**
 * Auth Code Entity Interface
 *
 * Custom implementation of the OAuth2 Server AuthCodeEntityInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface AuthCodeEntityInterface
{
    /**
     * Get the authorization code's identifier
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Set the authorization code's identifier
     *
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void;

    /**
     * Get the authorization code's expiry date/time
     *
     * @return \DateTimeImmutable
     */
    public function getExpiryDateTime(): \DateTimeImmutable;

    /**
     * Set the date/time when the authorization code expires
     *
     * @param \DateTimeImmutable $dateTime
     */
    public function setExpiryDateTime(\DateTimeImmutable $dateTime): void;

    /**
     * Set the user identifier of the authorization code
     *
     * @param string|int|null $identifier The user identifier
     */
    public function setUserIdentifier($identifier): void;

    /**
     * Get the user identifier of the authorization code
     *
     * @return string|int|null
     */
    public function getUserIdentifier();

    /**
     * Set the client that the authorization code was issued to
     *
     * @param ClientEntityInterface $client
     */
    public function setClient(ClientEntityInterface $client): void;

    /**
     * Get the client that the authorization code was issued to
     *
     * @return ClientEntityInterface
     */
    public function getClient(): ClientEntityInterface;

    /**
     * Add a scope to the authorization code
     *
     * @param ScopeEntityInterface $scope
     */
    public function addScope(ScopeEntityInterface $scope): void;

    /**
     * Get all scopes associated with the authorization code
     *
     * @return ScopeEntityInterface[]
     */
    public function getScopes(): array;
}
