<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\ClientEntity;

/**
 * Client Repository
 *
 * Handles retrieving OAuth client information
 */
class ClientRepository implements ClientRepositoryInterface
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
     * Get a client by client identifier
     *
     * @param string $clientIdentifier The client's identifier
     * @param string|null $grantType The grant type used
     * @param string|null $clientSecret The client's secret (if sent)
     * @param bool $mustValidateSecret If true, client_secret must be validated
     * @return ClientEntity|null
     */
    public function getClientEntity(
        $clientIdentifier,
        $grantType = null,
        $clientSecret = null,
        $mustValidateSecret = true
    ) {
        // Query for the client
        $clients = $this->queryBuilder->select('oauth_clients', [
                'id', 'name', 'secret', 'redirect_uri', 'is_confidential'
            ])
            ->where(['id' => $clientIdentifier])
            ->limit(1)
            ->get();

        if (empty($clients)) {
            return null;
        }

        $client = $clients[0];

        // Validate client secret if required
        if ($mustValidateSecret && $client['is_confidential'] == 1) {
            // If client is confidential, we must validate the secret
            if ($clientSecret === null) {
                return null; // No secret provided
            }

            if (!password_verify($clientSecret, $client['secret'])) {
                return null; // Invalid secret
            }
        }

        // Create and populate client entity
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier($client['id']);
        $clientEntity->setName($client['name']);
        $clientEntity->setRedirectUri($client['redirect_uri']);
        $clientEntity->setConfidential((bool)$client['is_confidential']);

        return $clientEntity;
    }
}
