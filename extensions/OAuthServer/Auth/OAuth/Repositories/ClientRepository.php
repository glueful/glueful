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
    ): ?ClientEntity {
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

    /**
     * Validate a client's credentials
     *
     * @param string      $clientIdentifier     The client's identifier
     * @param string|null $clientSecret         The client's secret (if sent)
     * @param string|null $grantType           The type of grant the client is using (if sent)
     *
     * @return bool
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = $this->getClientEntity($clientIdentifier, $grantType, $clientSecret);

        return $client !== null;
    }
    /**
     * Get a client by its ID
     *
     * @param string $clientId The client identifier
     * @return \Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\ClientEntity|null Client entity or null if not found
     */
    public function getClientById(string $clientId): ?ClientEntity
    {
        try {
            // Using the query builder instance from the parent repository
            $data = $this->queryBuilder
                ->select('oauth_clients')
                ->where(['id' => $clientId])
                ->first();

            if (!$data) {
                return null;
            }

            // Convert serialized fields to arrays
            $redirectUris = json_decode($data['redirect_uris'] ?? '[]', true);
            $allowedGrantTypes = json_decode($data['allowed_grant_types'] ?? '[]', true);

            // Create a proper client entity
            $client = new ClientEntity();
            $client->setIdentifier($data['id']);
            $client->setName($data['name']);
            $client->setRedirectUri($data['redirect_uri']);
            $client->setConfidential((bool)$data['is_confidential']);

            return $client;
        } catch (\Exception $e) {
            // Log error
            error_log('Error fetching client: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Get a client by its ID and validate the secret
     *
     * @param string $clientId The client identifier
     * @param string $clientSecret The client secret
     * @return object|null Client entity or null if not found/invalid
     */
    public function getClientByIdAndSecret(string $clientId, string $clientSecret): ?object
    {
        try {
            // Using the query builder instance from the parent repository
            $client = $this->queryBuilder
                ->select('oauth_clients')
                ->where(['id' => $clientId])
                ->first();

            if (!$client) {
                return null;
            }

            // Verify client secret
            if (!password_verify($clientSecret, $client['secret'])) {
                return null;
            }

            // Convert serialized fields to arrays
            $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
            $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);

            // Convert array to object
            $clientObject = (object) $client;

            // Add a getRedirectUris method to the client object
            $clientObject->getRedirectUris = function () use ($client) {
                return $client['redirect_uris'];
            };

            return $clientObject;
        } catch (\Exception $e) {
            // Log error
            error_log('Error fetching client: ' . $e->getMessage());
            return null;
        }
    }
}
