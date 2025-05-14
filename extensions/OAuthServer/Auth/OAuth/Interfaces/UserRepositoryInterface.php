<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * User Repository Interface
 *
 * Custom implementation of the OAuth2 Server UserRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface UserRepositoryInterface
{
    /**
     * Get a user entity for the given credentials
     *
     * @param string $username
     * @param string $password
     * @param string $grantType
     * @param ClientEntityInterface $clientEntity
     * @return mixed
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    );
}
