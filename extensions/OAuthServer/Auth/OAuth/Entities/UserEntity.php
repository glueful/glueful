<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * User Entity
 *
 * Represents a user for OAuth purposes
 */
class UserEntity implements UserEntityInterface
{
    /**
     * @var string|int User identifier
     */
    private $identifier;

    /**
     * @var string Username
     */
    private string $username = '';

    /**
     * @var string Email address
     */
    private string $email = '';

    /**
     * @var string User role
     */
    private string $role = '';


    /**
     * Set the user identifier
     *
     * @param string|int $identifier
     * @return void
     */
    public function setIdentifier($identifier): void
    {
        $this->identifier = $identifier;
    }

   /**
     * Get the user identifier
     *
     * @return string User identifier
     */
    public function getIdentifier(): string
    {
        return (string) $this->identifier;
    }

    /**
     * Get the username
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Set the username
     *
     * @param string $username
     * @return void
     */
    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    /**
     * Get the email
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Set the email
     *
     * @param string $email
     * @return void
     */
    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    /**
     * Get the user role
     *
     * @return string
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Set the user role
     *
     * @param string $role
     * @return void
     */
    public function setRole(string $role): void
    {
        $this->role = $role;
    }
}
