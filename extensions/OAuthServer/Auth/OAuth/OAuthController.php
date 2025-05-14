<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth;

use Glueful\Container;
use Glueful\Database\Connection;
use Glueful\Http\Response;
use Glueful\Http\Request;
use Glueful\Http\Controller;

/**
 * OAuth Controller
 *
 * Handles OAuth 2.0 endpoints for authorization, token issuance, and management.
 *
 * @package Glueful\Extensions\OAuthServer\Auth\OAuth
 */
class OAuthController extends Controller
{
    /**
     * @var OAuthServer OAuth server instance
     */
    private OAuthServer $oauthServer;

    /**
     * @var Connection Database connection
     */
    private Connection $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->oauthServer = Container::resolve('oauth.server');
        $this->db = Connection::getInstance();
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
            $requestData = $request->getPostParams();

            // Check for HTTP Basic Auth for client credentials
            $authHeader = $request->getHeader('Authorization');
            if ($authHeader && strpos(strtolower($authHeader), 'basic ') === 0) {
                $credentials = base64_decode(substr($authHeader, 6));
                list($clientId, $clientSecret) = explode(':', $credentials);

                $requestData['client_id'] = $clientId;
                $requestData['client_secret'] = $clientSecret;
            }

            $tokenResponse = $this->oauthServer->issueToken($requestData);

            return Response::json($tokenResponse);
        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'An unexpected error occurred'
            ], 500);
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
        $params = $request->getQueryParams();

        // Validate required parameters
        if (empty($params['response_type']) || $params['response_type'] !== 'code') {
            return Response::json([
                'error' => 'unsupported_response_type',
                'error_description' => 'Only code response type is supported'
            ], 400);
        }

        if (empty($params['client_id'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client ID is required'
            ], 400);
        }

        if (empty($params['redirect_uri'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Redirect URI is required'
            ], 400);
        }

        // Verify the client
        $client = $this->oauthServer->getClientRepository()->getClientById($params['client_id']);

        if (!$client) {
            return Response::json([
                'error' => 'invalid_client',
                'error_description' => 'Client not found'
            ], 400);
        }

        // Verify if redirect URI is registered for this client
        if (!$this->isValidRedirectUri($client, $params['redirect_uri'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Invalid redirect URI for this client'
            ], 400);
        }

        // Check if user is authenticated
        $user = $request->getUser();

        if (!$user) {
            // User is not authenticated, redirect to login
            // Store the authorization request in the session
            $session = $request->getSession();
            $session->set('oauth_auth_request', $params);

            // Redirect to login page
            return Response::redirect('/login?redirect_after=' . urlencode('/oauth/authorize'));
        }

        // Display authorization form
        $scopes = !empty($params['scope']) ? explode(' ', $params['scope']) : [];

        $viewData = [
            'client' => $client,
            'scopes' => $scopes,
            'user' => $user,
            'request_params' => $params
        ];

        return Response::view('oauth/authorize', $viewData);
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
        $params = $request->getPostParams();

        // Check if approval was granted
        if (empty($params['approve']) || $params['approve'] !== 'true') {
            // User denied the authorization
            $redirectUri = $params['redirect_uri'];
            $redirectUri .= strpos($redirectUri, '?') === false ? '?' : '&';
            $redirectUri .= 'error=access_denied&error_description=' . urlencode('The user denied the request');

            return Response::redirect($redirectUri);
        }

        // User approved, create authorization code
        $user = $request->getUser();

        if (!$user) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'User session expired'
            ], 400);
        }

        // Parse requested scopes
        $scopes = !empty($params['scope']) ? explode(' ', $params['scope']) : [];

        // Get code challenge for PKCE
        $codeChallenge = $params['code_challenge'] ?? null;
        $codeChallengeMethod = $params['code_challenge_method'] ?? 'plain';

        // Create authorization code
        try {
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

            return Response::redirect($redirectUri);
        } catch (\Exception $e) {
            $redirectUri = $params['redirect_uri'];
            $redirectUri .= strpos($redirectUri, '?') === false ? '?' : '&';
            $redirectUri .= 'error=server_error&error_description=' . urlencode('Failed to create authorization code');

            return Response::redirect($redirectUri);
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
        $params = $request->getPostParams();

        // Validate required parameters
        if (empty($params['token'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Token is required'
            ], 400);
        }

        if (empty($params['client_id'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client ID is required'
            ], 400);
        }

        // Optional token_type_hint - default to access token
        $tokenTypeHint = $params['token_type_hint'] ?? 'access_token';

        try {
            // Revoke the token based on type hint
            $revoked = false;

            if ($tokenTypeHint === 'refresh_token') {
                $revoked = $this->oauthServer->revokeRefreshToken($params['token']);
            } else {
                $revoked = $this->oauthServer->revokeAccessToken($params['token']);

                // If not found as access token, try as refresh token
                if (!$revoked) {
                    $revoked = $this->oauthServer->revokeRefreshToken($params['token']);
                }
            }

            // According to RFC 7009, always return 200 OK even if token was invalid
            return Response::json(null, 200);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'An unexpected error occurred'
            ], 500);
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
        $params = $request->getPostParams();

        // Validate required parameters
        if (empty($params['token'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Token is required'
            ], 400);
        }

        // Verify client authentication
        $clientId = $params['client_id'] ?? null;
        $clientSecret = $params['client_secret'] ?? null;

        if (!$clientId || !$clientSecret) {
            // Check HTTP Basic Auth header
            $authHeader = $request->getHeader('Authorization');
            if ($authHeader && strpos(strtolower($authHeader), 'basic ') === 0) {
                $credentials = base64_decode(substr($authHeader, 6));
                list($clientId, $clientSecret) = explode(':', $credentials);
            }
        }

        if (!$clientId || !$clientSecret) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client authentication required'
            ], 401);
        }

        // Verify the client
        $client = $this->oauthServer->getClientRepository()->getClientByIdAndSecret(
            $clientId,
            $clientSecret
        );

        if (!$client) {
            return Response::json([
                'active' => false
            ]);
        }

        try {
            // Validate the token
            $tokenInfo = $this->oauthServer->validateToken($params['token']);

            if (!$tokenInfo) {
                return Response::json([
                    'active' => false
                ]);
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

            return Response::json($response);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'An unexpected error occurred'
            ], 500);
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
            $stmt = $this->db->prepare("
                SELECT id, name, description, redirect_uris, allowed_grant_types, 
                       created_at, updated_at
                FROM oauth_clients
                ORDER BY name ASC
            ");

            $stmt->execute();
            $clients = $stmt->fetchAll();

            // Process clients - convert serialized data
            foreach ($clients as &$client) {
                $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
                $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);
            }

            return Response::json([
                'clients' => $clients
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'Failed to list clients'
            ], 500);
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
        $params = $request->getPostParams();

        // Validate required parameters
        if (empty($params['name'])) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client name is required'
            ], 400);
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

        try {
            $stmt = $this->db->prepare("
                INSERT INTO oauth_clients
                (id, secret, name, description, redirect_uris, allowed_grant_types, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $now = time();
            $stmt->execute([
                $clientId,
                password_hash($clientSecret, PASSWORD_DEFAULT),
                $params['name'],
                $params['description'] ?? null,
                json_encode($redirectUris),
                json_encode($allowedGrantTypes),
                $now,
                $now
            ]);

            return Response::json([
                'client_id' => $clientId,
                'client_secret' => $clientSecret, // Only shown once
                'name' => $params['name'],
                'description' => $params['description'] ?? null,
                'redirect_uris' => $redirectUris,
                'allowed_grant_types' => $allowedGrantTypes,
                'created_at' => $now,
                'updated_at' => $now
            ], 201);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'Failed to create client: ' . $e->getMessage()
            ], 500);
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
        $clientId = $params['id'] ?? null;

        if (!$clientId) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client ID is required'
            ], 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id, name, description, redirect_uris, allowed_grant_types, 
                       created_at, updated_at
                FROM oauth_clients
                WHERE id = ?
            ");

            $stmt->execute([$clientId]);
            $client = $stmt->fetch();

            if (!$client) {
                return Response::json([
                    'error' => 'not_found',
                    'error_description' => 'Client not found'
                ], 404);
            }

            // Process client - convert serialized data
            $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
            $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);

            return Response::json($client);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'Failed to retrieve client'
            ], 500);
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
        $clientId = $params['id'] ?? null;

        if (!$clientId) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client ID is required'
            ], 400);
        }

        $requestData = $request->getPostParams();

        // Check if client exists
        $stmt = $this->db->prepare("SELECT id FROM oauth_clients WHERE id = ?");
        $stmt->execute([$clientId]);
        if (!$stmt->fetch()) {
            return Response::json([
                'error' => 'not_found',
                'error_description' => 'Client not found'
            ], 404);
        }

        // Build update query
        $updateFields = [];
        $updateParams = [];

        if (isset($requestData['name'])) {
            $updateFields[] = "name = ?";
            $updateParams[] = $requestData['name'];
        }

        if (isset($requestData['description'])) {
            $updateFields[] = "description = ?";
            $updateParams[] = $requestData['description'];
        }

        if (isset($requestData['redirect_uris'])) {
            $redirectUris = $requestData['redirect_uris'];
            if (!is_array($redirectUris)) {
                $redirectUris = explode(',', $redirectUris);
            }
            $updateFields[] = "redirect_uris = ?";
            $updateParams[] = json_encode($redirectUris);
        }

        if (isset($requestData['allowed_grant_types'])) {
            $grantTypes = $requestData['allowed_grant_types'];
            if (!is_array($grantTypes)) {
                $grantTypes = explode(',', $grantTypes);
            }
            $updateFields[] = "allowed_grant_types = ?";
            $updateParams[] = json_encode($grantTypes);
        }

        // Regenerate client secret if requested
        $newSecret = null;
        if (!empty($requestData['reset_secret']) && $requestData['reset_secret'] === 'true') {
            $newSecret = bin2hex(random_bytes(32));
            $updateFields[] = "secret = ?";
            $updateParams[] = password_hash($newSecret, PASSWORD_DEFAULT);
        }

        // Add updated_at timestamp
        $updateFields[] = "updated_at = ?";
        $updateParams[] = time();

        // Add client ID to parameters
        $updateParams[] = $clientId;

        if (empty($updateFields)) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'No fields to update'
            ], 400);
        }

        try {
            $sql = "UPDATE oauth_clients SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateParams);

            // Get updated client
            $stmt = $this->db->prepare("
                SELECT id, name, description, redirect_uris, allowed_grant_types, 
                       created_at, updated_at
                FROM oauth_clients
                WHERE id = ?
            ");

            $stmt->execute([$clientId]);
            $client = $stmt->fetch();

            // Process client - convert serialized data
            $client['redirect_uris'] = json_decode($client['redirect_uris'] ?? '[]', true);
            $client['allowed_grant_types'] = json_decode($client['allowed_grant_types'] ?? '[]', true);

            // Include new client secret if it was reset
            if ($newSecret) {
                $client['client_secret'] = $newSecret;
            }

            return Response::json($client);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'Failed to update client: ' . $e->getMessage()
            ], 500);
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
        $clientId = $params['id'] ?? null;

        if (!$clientId) {
            return Response::json([
                'error' => 'invalid_request',
                'error_description' => 'Client ID is required'
            ], 400);
        }

        try {
            // Check if client exists
            $stmt = $this->db->prepare("SELECT id FROM oauth_clients WHERE id = ?");
            $stmt->execute([$clientId]);
            if (!$stmt->fetch()) {
                return Response::json([
                    'error' => 'not_found',
                    'error_description' => 'Client not found'
                ], 404);
            }

            // Delete all related tokens first
            $this->db->prepare("DELETE FROM oauth_access_tokens WHERE client_id = ?")->execute([$clientId]);
            $this->db->prepare("DELETE FROM oauth_refresh_tokens WHERE client_id = ?")->execute([$clientId]);
            $this->db->prepare("DELETE FROM oauth_authorization_codes WHERE client_id = ?")->execute([$clientId]);

            // Delete client
            $stmt = $this->db->prepare("DELETE FROM oauth_clients WHERE id = ?");
            $stmt->execute([$clientId]);

            return Response::json([
                'success' => true
            ], 200);
        } catch (\Exception $e) {
            return Response::json([
                'error' => 'server_error',
                'error_description' => 'Failed to delete client: ' . $e->getMessage()
            ], 500);
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
