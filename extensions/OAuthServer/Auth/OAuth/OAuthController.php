<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth Controller
 *
 * Handles OAuth 2.0 endpoints for authorization, token issuance, and management.
 *
 * @package Glueful\Extensions\OAuthServer\Auth\OAuth
 */
class OAuthController
{
    /**
     * @var OAuthServer OAuth server instance
     */
    private OAuthServer $oauthServer;

    /**
     * @var QueryBuilder Database query builder
     */
    private QueryBuilder $queryBuilder;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->oauthServer = new OAuthServer();

        // Initialize query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder(
            $connection->getPDO(),
            $connection->getDriver()
        );
    }

    /**
     * OAuth token endpoint
     *
     * Handles token requests for various grant types:
     * - authorization_code
     * - refresh_token
     * - client_credentials
     * - password
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function token(Request $request): Response
    {
        try {
            $requestData = $request->request->all();

            // Check for HTTP Basic Auth for client credentials
            $authHeader = $request->headers->get('Authorization');
            if ($authHeader && strpos(strtolower($authHeader), 'basic ') === 0) {
                $credentials = base64_decode(substr($authHeader, 6));
                list($clientId, $clientSecret) = explode(':', $credentials);

                $requestData['client_id'] = $clientId;
                $requestData['client_secret'] = $clientSecret;
            }

            $tokenResponse = $this->oauthServer->issueToken($requestData);

            return Response::ok($tokenResponse)->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error(
                'invalid_request: ' . $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            )->send();
        } catch (\Exception $e) {
            return Response::error(
                'server_error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * OAuth authorization endpoint
     *
     * Displays authorization form for authorization code flow.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function authorize(Request $request): Response
    {
        try {
            $params = $request->query->all();

            // Validate required parameters
            if (empty($params['response_type']) || $params['response_type'] !== 'code') {
                return Response::error(
                    'Only code response type is supported',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'unsupported_response_type',
                    'error_description' => 'Only code response type is supported']
                )->send();
            }

            if (empty($params['client_id'])) {
                return Response::error(
                    'Client ID is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client ID is required']
                )->send();
            }

            if (empty($params['redirect_uri'])) {
                return Response::error(
                    'Redirect URI is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Redirect URI is required']
                )->send();
            }

            // Verify the client
            $client = $this->oauthServer->getClientRepository()->getClientById($params['client_id']);

            if (!$client) {
                return Response::error(
                    'Client not found',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_client', 'error_description' => 'Client not found']
                )->send();
            }

            // Verify if redirect URI is registered for this client
            if (!$this->isValidRedirectUri($client, $params['redirect_uri'])) {
                return Response::error(
                    'Invalid redirect URI for this client',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Invalid redirect URI for this client']
                )->send();
            }

            // Check if user is authenticated
            $user = $request->getUser();

            if (!$user) {
                // User is not authenticated, redirect to login
                // Store the authorization request in the session
                $session = $request->getSession();
                $session->set('oauth_auth_request', $params);

                // Set up a redirect response manually since Response::redirect() doesn't exist
                return Response::ok([
                    'redirect' => '/login?redirect_after=' . urlencode('/oauth/authorize')
                ], 'Redirecting to login')->send();
            }

            // Display authorization form
            $scopes = !empty($params['scope']) ? explode(' ', $params['scope']) : [];

            $viewData = [
                'client' => $client,
                'scopes' => $scopes,
                'user' => $user,
                'request_params' => $params,
                'view' => 'oauth/authorize' // Specify which view to render
            ];

            return Response::ok($viewData)->send();
        } catch (\Exception $e) {
            return Response::error(
                'server_error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Process authorization approval
     *
     * Handles the user's decision to approve or deny the authorization request.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function approveAuthorization(Request $request): Response
    {
        try {
            $params = $request->request->all();

            // Check if approval was granted
            if (empty($params['approve']) || $params['approve'] !== 'true') {
                // User denied the authorization
                $redirectUri = $params['redirect_uri'];
                $redirectUri .= strpos($redirectUri, '?') === false ? '?' : '&';
                $redirectUri .= 'error=access_denied&error_description=' . urlencode('The user denied the request');

                // Set up a redirect response manually
                return Response::ok([
                    'redirect' => $redirectUri
                ], 'Redirecting with error')->send();
            }

            // User approved, create authorization code
            $user = $request->getUser();

            if (!$user) {
                return Response::error(
                    'User session expired',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'server_error', 'error_description' => 'User session expired']
                )->send();
            }

            // Parse requested scopes
            $scopes = !empty($params['scope']) ? explode(' ', $params['scope']) : [];

            // Get code challenge for PKCE
            $codeChallenge = $params['code_challenge'] ?? null;
            $codeChallengeMethod = $params['code_challenge_method'] ?? 'plain';

            $authCode = $this->oauthServer->createAuthorizationCode(
                $params['client_id'],
                $user['id'],
                $params['redirect_uri'],
                $scopes,
                $codeChallenge,
                $codeChallengeMethod
            );

            // Build redirect URL with code
            $redirectUri = $params['redirect_uri'];
            $redirectUri .= strpos($redirectUri, '?') === false ? '?' : '&';
            $redirectUri .= 'code=' . urlencode($authCode);

            // Add state if provided
            if (!empty($params['state'])) {
                $redirectUri .= '&state=' . urlencode($params['state']);
            }

            // Set up a redirect response manually
            return Response::ok([
                'redirect' => $redirectUri
            ], 'Redirecting with authorization code')->send();
        } catch (\Exception $e) {
            $redirectUri = $params['redirect_uri'] ?? '/';
            $redirectUri .= strpos($redirectUri, '?') === false ? '?' : '&';
            $redirectUri .= 'error=server_error&error_description=' . urlencode('Failed to create authorization code');

            // Set up a redirect response manually
            return Response::ok([
                'redirect' => $redirectUri
            ], 'Redirecting with error')->send();
        }
    }

    /**
     * OAuth token revocation endpoint
     *
     * Allows clients to revoke access or refresh tokens.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function revoke(Request $request): Response
    {
        try {
            $params = $request->request->all();

            // Validate required parameters
            if (empty($params['token'])) {
                return Response::error(
                    'Token is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Token is required']
                )->send();
            }

            if (empty($params['client_id'])) {
                return Response::error(
                    'Client ID is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client ID is required']
                )->send();
            }

            // Optional token_type_hint - default to access token
            $tokenTypeHint = $params['token_type_hint'] ?? 'access_token';

            // Revoke the token based on type hint
            $revoked = false;

            if ($tokenTypeHint === 'refresh_token') {
                $revoked = $this->oauthServer->revokeToken($params['token'], 'refresh_token');
            } else {
                $revoked = $this->oauthServer->revokeToken($params['token'], 'access_token');

                // If not found as access token, try as refresh token
                if (!$revoked) {
                    $revoked = $this->oauthServer->revokeToken($params['token'], 'refresh_token');
                }
            }

            // According to RFC 7009, always return 200 OK even if token was invalid
            return Response::ok(null, 'Token revocation successful')->send();
        } catch (\Exception $e) {
            return Response::error(
                'An unexpected error occurred',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'An unexpected error occurred']
            )->send();
        }
    }

    /**
     * OAuth token introspection endpoint
     *
     * Returns metadata about a token.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function introspect(Request $request): Response
    {
        try {
            $params = $request->request->all();

            // Validate required parameters
            if (empty($params['token'])) {
                return Response::error(
                    'Token is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Token is required']
                )->send();
            }

            // Verify client authentication
            $clientId = $params['client_id'] ?? null;
            $clientSecret = $params['client_secret'] ?? null;

            if (!$clientId || !$clientSecret) {
                // Check HTTP Basic Auth header
                $authHeader = $request->headers->get('Authorization');
                if ($authHeader && strpos(strtolower($authHeader), 'basic ') === 0) {
                    $credentials = base64_decode(substr($authHeader, 6));
                    list($clientId, $clientSecret) = explode(':', $credentials);
                }
            }

            if (!$clientId || !$clientSecret) {
                return Response::unauthorized(
                    'Client authentication required'
                )->send();
            }

            // Verify the client
            $client = $this->oauthServer->getClientRepository()->getClientByIdAndSecret(
                $clientId,
                $clientSecret
            );

            if (!$client) {
                return Response::ok(['active' => false])->send();
            }

            // Validate the token
            $tokenInfo = $this->oauthServer->validateToken($params['token']);

            if (!$tokenInfo) {
                return Response::ok(['active' => false])->send();
            }

            // Prepare introspection response
            $response = [
                'active' => true,
                'client_id' => $tokenInfo['client_id'],
                'scope' => implode(' ', $tokenInfo['scopes']),
                'exp' => $tokenInfo['expires_at']
            ];

            // Include user information if present
            if (!empty($tokenInfo['user_id'])) {
                $response['sub'] = $tokenInfo['user_id'];
            }

            return Response::ok($response)->send();
        } catch (\Exception $e) {
            return Response::error(
                'An unexpected error occurred',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'An unexpected error occurred']
            )->send();
        }
    }

    /**
     * List OAuth clients
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function listClients(Request $request): Response
    {
        try {
            // Using QueryBuilder instead of direct PDO
            $clients = $this->queryBuilder
                ->select('oauth_clients', [
                    'id', 'name', 'description', 'redirect_uris',
                    'allowed_grant_types', 'created_at', 'updated_at'
                ])
                ->orderBy(['name' => 'ASC'])
                ->get();

            // Process clients - convert serialized data
            foreach ($clients as &$client) {
                $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
                $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);
            }

            return Response::ok(['clients' => $clients])->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to list clients',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'Failed to list clients']
            )->send();
        }
    }

    /**
     * Create a new OAuth client
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function createClient(Request $request): Response
    {
        try {
            $params = $request->request->all();

            // Validate required parameters
            if (empty($params['name'])) {
                return Response::error(
                    'Client name is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client name is required']
                )->send();
            }

            // Generate client ID and secret
            $clientId = bin2hex(random_bytes(16));
            $clientSecret = bin2hex(random_bytes(32));

            // Prepare grant types
            $allowedGrantTypes = $params['allowed_grant_types'] ?? ['authorization_code'];
            if (!is_array($allowedGrantTypes)) {
                $allowedGrantTypes = explode(',', $allowedGrantTypes);
            }

            // Prepare redirect URIs
            $redirectUris = $params['redirect_uris'] ?? [];
            if (!is_array($redirectUris)) {
                $redirectUris = explode(',', $redirectUris);
            }

            $now = time();

            // Using QueryBuilder's insert method
            $this->queryBuilder->insert('oauth_clients', [
                'id' => $clientId,
                'secret' => password_hash($clientSecret, PASSWORD_DEFAULT),
                'name' => $params['name'],
                'description' => $params['description'] ?? null,
                'redirect_uris' => json_encode($redirectUris),
                'allowed_grant_types' => json_encode($allowedGrantTypes),
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $clientData = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret, // Only shown once
                'name' => $params['name'],
                'description' => $params['description'] ?? null,
                'redirect_uris' => $redirectUris,
                'allowed_grant_types' => $allowedGrantTypes,
                'created_at' => $now,
                'updated_at' => $now
            ];

            return Response::created($clientData, 'OAuth client created successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to create client: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'Failed to create client: ' . $e->getMessage()]
            )->send();
        }
    }

    /**
     * Get OAuth client details
     *
     * @param Request $request HTTP request
     * @param array $params Route parameters
     * @return Response HTTP response
     */
    public function getClient(Request $request, array $params = []): Response
    {
        try {
            $clientId = $params['id'] ?? null;

            if (!$clientId) {
                return Response::error(
                    'Client ID is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client ID is required']
                )->send();
            }

            // Using QueryBuilder to select client
            $client = $this->queryBuilder
                ->select('oauth_clients', [
                    'id', 'name', 'description', 'redirect_uris',
                    'allowed_grant_types', 'created_at', 'updated_at'
                ])
                ->where(['id' => $clientId])
                ->first();

            if (!$client) {
                return Response::notFound('Client not found')->send();
            }

            // Process client - convert serialized data
            $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
            $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);

            return Response::ok($client)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve client',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'Failed to retrieve client']
            )->send();
        }
    }

    /**
     * Update an OAuth client
     *
     * @param Request $request HTTP request
     * @param array $params Route parameters
     * @return Response HTTP response
     */
    public function updateClient(Request $request, array $params = []): Response
    {
        try {
            $clientId = $params['id'] ?? null;

            if (!$clientId) {
                return Response::error(
                    'Client ID is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client ID is required']
                )->send();
            }

            $requestData = $request->request->all();

            // Check if client exists
            $clientExists = $this->queryBuilder
                ->select('oauth_clients', ['id'])
                ->where(['id' => $clientId])
                ->first();

            if (!$clientExists) {
                return Response::notFound('Client not found')->send();
            }

            $updateData = [];

            if (isset($requestData['name'])) {
                $updateData['name'] = $requestData['name'];
            }

            if (isset($requestData['description'])) {
                $updateData['description'] = $requestData['description'];
            }

            if (isset($requestData['redirect_uris'])) {
                $redirectUris = $requestData['redirect_uris'];
                if (!is_array($redirectUris)) {
                    $redirectUris = explode(',', $redirectUris);
                }
                $updateData['redirect_uris'] = json_encode($redirectUris);
            }

            if (isset($requestData['allowed_grant_types'])) {
                $grantTypes = $requestData['allowed_grant_types'];
                if (!is_array($grantTypes)) {
                    $grantTypes = explode(',', $grantTypes);
                }
                $updateData['allowed_grant_types'] = json_encode($grantTypes);
            }

            // Regenerate client secret if requested
            $newSecret = null;
            if (!empty($requestData['reset_secret']) && $requestData['reset_secret'] === 'true') {
                $newSecret = bin2hex(random_bytes(32));
                $updateData['secret'] = password_hash($newSecret, PASSWORD_DEFAULT);
            }

            // Add updated_at timestamp
            $updateData['updated_at'] = time();

            if (empty($updateData)) {
                return Response::error(
                    'No fields to update',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'No fields to update']
                )->send();
            }

            // Using QueryBuilder's update method
            $this->queryBuilder->update('oauth_clients', $updateData, ['id' => $clientId]);

            // Get updated client
            $client = $this->queryBuilder
                ->select('oauth_clients', [
                    'id', 'name', 'description', 'redirect_uris',
                    'allowed_grant_types', 'created_at', 'updated_at'
                ])
                ->where(['id' => $clientId])
                ->first();

            // Process client - convert serialized data
            $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
            $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);

            // Include new client secret if it was reset
            if ($newSecret) {
                $client['client_secret'] = $newSecret;
            }

            return Response::ok($client, 'Client updated successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to update client: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'Failed to update client: ' . $e->getMessage()]
            )->send();
        }
    }

    /**
     * Delete an OAuth client
     *
     * @param Request $request HTTP request
     * @param array $params Route parameters
     * @return Response HTTP response
     */
    public function deleteClient(Request $request, array $params = []): Response
    {
        try {
            $clientId = $params['id'] ?? null;

            if (!$clientId) {
                return Response::error(
                    'Client ID is required',
                    Response::HTTP_BAD_REQUEST,
                    ['error' => 'invalid_request', 'error_description' => 'Client ID is required']
                )->send();
            }

            // Check if client exists
            $clientExists = $this->queryBuilder
                ->select('oauth_clients', ['id'])
                ->where(['id' => $clientId])
                ->first();

            if (!$clientExists) {
                return Response::notFound('Client not found')->send();
            }

            // Using QueryBuilder's transaction for related operations
            $this->queryBuilder->transaction(function ($queryBuilder) use ($clientId) {
                // Delete all related tokens first
                $queryBuilder->delete('oauth_access_tokens', ['client_id' => $clientId], false);
                $queryBuilder->delete('oauth_refresh_tokens', ['client_id' => $clientId], false);
                $queryBuilder->delete('oauth_authorization_codes', ['client_id' => $clientId], false);

                // Delete client
                $queryBuilder->delete('oauth_clients', ['id' => $clientId], false);
            });

            return Response::ok(['success' => true], 'Client deleted successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to delete client: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['error' => 'server_error', 'error_description' => 'Failed to delete client: ' . $e->getMessage()]
            )->send();
        }
    }

    /**
     * Check if a redirect URI is valid for a client
     *
     * @param object $client Client entity
     * @param string $redirectUri Redirect URI to check
     * @return bool True if valid
     */
    private function isValidRedirectUri($client, string $redirectUri): bool
    {
        $registeredUris = $client->getRedirectUris();

        // If no URIs are registered, none are valid
        if (empty($registeredUris)) {
            return false;
        }

        // Check exact match
        if (in_array($redirectUri, $registeredUris)) {
            return true;
        }

        return false;
    }
}
