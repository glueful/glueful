<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\AuthCodeEntity;

/**
 * Auth Code Repository
 *
 * Handles storing and retrieving OAuth authorization codes
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    /**
     * @var QueryBuilder Database query builder instance
     */
    private QueryBuilder $queryBuilder;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize database connection and query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Create a new authorization code
     *
     * @return AuthCodeEntityInterface
     */
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    /**
     * Persist a new authorization code to storage
     *
     * @param AuthCodeEntityInterface $authCodeEntity
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        // Extract scope strings from scope entities
        $scopes = [];
        foreach ($authCodeEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        $this->queryBuilder->insert('oauth_auth_codes', [
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Revoke an authorization code
     *
     * @param string $codeId
     */
    public function revokeAuthCode($codeId): void
    {
        $this->queryBuilder->update(
            'oauth_auth_codes',
            ['revoked' => true],
            ['id' => $codeId]
        );
    }

    /**
     * Check if an authorization code has been revoked
     *
     * @param string $codeId
     * @return bool
     */
    public function isAuthCodeRevoked($codeId): bool
    {
        $code = $this->queryBuilder->select('oauth_auth_codes', ['revoked'])
            ->where(['id' => $codeId])
            ->limit(1)
            ->get();

        if (empty($code)) {
            return true; // Auth code not found, consider it revoked
        }

        return (bool) $code[0]['revoked'];
    }
     /**
     * Create a new authorization code
     *
     * @param string $code The authorization code
     * @param string $clientId The client ID
     * @param string $userId The user ID
     * @param string $redirectUri The redirect URI
     * @param array $scopes The requested scopes
     * @param int $expiresAt Expiration timestamp
     * @param string|null $codeChallenge PKCE code challenge
     * @param string $codeChallengeMethod PKCE code challenge method
     * @return bool Success status
     */
    public function createAuthorizationCode(
        string $code,
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes = [],
        int $expiresAt = 0,
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'plain'
    ): bool {
        // Implementation to store the auth code in the database
        $connection = new \Glueful\Database\Connection();
        $queryBuilder = new \Glueful\Database\QueryBuilder($connection->getPDO(), $connection->getDriver());

        return $queryBuilder->insert('oauth_auth_codes', [
            'id' => $code,
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod
        ]) > 0;
    }
}
