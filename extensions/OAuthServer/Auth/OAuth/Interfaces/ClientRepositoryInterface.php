<?php

declare(strict_types=1);

namespace League\OAuth2\Server\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Client Repository Interface
 *
 * Custom implementation of the OAuth2 Server ClientRepositoryInterface
 * to avoid dependency on the League OAuth2 Server package.
 */
interface ClientRepositoryInterface
{
    /**
     * Get a client by client identifier
     *
     * @param string $clientIdentifier The client's identifier
     * @param string|null $grantType The grant type used
     * @param string|null $clientSecret The client's secret (if sent)
     * @param bool $mustValidateSecret If true, client_secret must be validated
     * @return ClientEntityInterface|null
     */
    public function getClientEntity(
        $clientIdentifier,
        $grantType = null,
        $clientSecret = null,
        $mustValidateSecret = true
    );
}
