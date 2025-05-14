<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Auth Code Repository Interface
 *
 * Custom implementation of the OAuth2 Server AuthCodeRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface AuthCodeRepositoryInterface
{
    /**
     * Create a new authorization code
     *
     * @return AuthCodeEntityInterface
     */
    public function getNewAuthCode();

    /**
     * Persist a new authorization code to storage
     *
     * @param AuthCodeEntityInterface $authCodeEntity
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity);

    /**
     * Revoke an authorization code
     *
     * @param string $codeId
     */
    public function revokeAuthCode($codeId);

    /**
     * Check if an authorization code has been revoked
     *
     * @param string $codeId
     * @return bool
     */
    public function isAuthCodeRevoked($codeId);
}
