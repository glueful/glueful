<?php

declare(strict_types=1);

use Glueful\Http\Router;
use Symfony\Component\Routing\Annotation\Route;

$controller = new \Glueful\Extensions\OAuthServer\Auth\OAuth\OAuthController();

/*
 * OAuth Server Routes
 *
 * This file defines routes for the OAuth server functionality:
 * - Token issuance endpoints
 * - Authorization endpoints
 * - Token management endpoints
 * - OAuth client management endpoints
 */

// OAuth server endpoints
Router::group('/oauth', function () use ($controller) {
    /**
     * @route POST /oauth/token
     * @summary Token Issuance
     * @description Issues access and refresh tokens for authorized clients
     * @tag OAuth
     * @requestBody grant_type:string="The OAuth grant type (authorization_code, refresh_token, client_credentials,
     * password)"
     *              code:string="The authorization code (required for authorization_code grant)"
     *              redirect_uri:string="The redirect URI (required for authorization_code grant)"
     *              client_id:string="The client ID (required for all grants except when using HTTP Basic auth)"
     *              client_secret:string="The client secret (required for all grants except when using HTTP Basic auth"
     *              ." or public clients)"
     *              username:string="The user's username (required for password grant)"
     *              password:string="The user's password (required for password grant)"
     *              refresh_token:string="The refresh token (required for refresh_token grant)"
     *              scope:string="Space-delimited list of requested scopes (optional)"
     * @response 200 application/json "Successfully issued tokens" {
     *   access_token:string="The access token",
     *   token_type:string="The token type, always 'Bearer'",
     *   expires_in:number="Token lifetime in seconds",
     *   refresh_token:string="The refresh token (not included in client_credentials grant)",
     *   scope:string="Space-delimited list of authorized scopes"
     * }
     * @response 400 application/json "Bad request" { error:string, error_description:string }
     * @response 401 application/json "Unauthorized" { error:string, error_description:string }
     */
    Router::post('/token', [$controller, 'token']);

    /**
     * @route GET /oauth/authorize
     * @summary Authorization Request
     * @description Initiates the authorization flow for the authorization code grant
     * @tag OAuth
     * @parameter response_type:string="The response type, must be 'code'" {required=true}
     * @parameter client_id:string="The client ID" {required=true}
     * @parameter redirect_uri:string="The redirect URI" {required=true}
     * @parameter scope:string="Space-delimited list of requested scopes" {required=false}
     * @parameter state:string="Client state" {required=false}
     * @parameter code_challenge:string="PKCE code challenge" {required=false}
     * @parameter code_challenge_method:string="PKCE code challenge method" {required=false}
     * @response 302 "Redirects to login page or authorization consent page"
     * @response 400 application/json "Bad request" { error:string, error_description:string }
     */
    Router::get('/authorize', [$controller, 'authorize']);

    /**
     * @route POST /oauth/authorize
     * @summary Authorization Approval
     * @description Processes user approval of an authorization request
     * @tag OAuth
     * @requestBody client_id:string="The client ID"
     *              scope:string="Space-delimited list of approved scopes"
     *              approved:boolean="Whether the request was approved"
     * @response 302 "Redirects to the client redirect_uri with authorization code or error"
     * @response 400 application/json "Bad request" { error:string, error_description:string }
     */
    Router::post('/authorize', [$controller, 'approveAuthorization']);

    /**
     * @route POST /oauth/revoke
     * @summary Token Revocation
     * @description Revokes an access or refresh token
     * @tag OAuth
     * @requestBody token:string="The token to revoke"
     *              token_type_hint:string="Type of token (access_token or refresh_token)"
     * @response 200 application/json "Successfully revoked token" {}
     * @response 400 application/json "Bad request" { error:string, error_description:string }
     */
    Router::post('/revoke', [$controller, 'revoke']);

    /**
     * @route POST /oauth/introspect
     * @summary Token Introspection
     * @description Provides information about a token
     * @tag OAuth
     * @requestBody token:string="The token to introspect"
     *              token_type_hint:string="Type of token (access_token or refresh_token)"
     * @response 200 application/json "Token information" {
     *   active:boolean="Whether the token is active",
     *   scope:string="Space-delimited list of scopes",
     *   client_id:string="Client ID the token was issued to",
     *   username:string="Username of the resource owner",
     *   exp:number="Expiration timestamp",
     *   iat:number="Issued at timestamp"
     * }
     */
    Router::post('/introspect', [$controller, 'introspect']);
});

Router::group('/oauth', function () use ($controller) {
    /**
     * @route GET /oauth/clients
     * @summary List OAuth Clients
     * @description Lists all registered OAuth clients
     * @tag OAuth
     * @security OAuthClientAuth
     * @response 200 application/json "List of OAuth clients" {
     *   clients:array="List of client objects"
     * }
     */
    Router::get('/clients', [$controller, 'listClients']);
});

// Admin routes for managing OAuth clients
Router::group('/admin/oauth', function () use ($controller) {
    /**
     * @route GET /admin/oauth/clients
     * @summary List OAuth Clients
     * @description Lists all registered OAuth clients
     * @tag OAuth Administration
     * @security AdminAuth
     * @response 200 application/json "List of OAuth clients" {
     *   clients:array="List of client objects"
     * }
     */
    Router::get('/clients', [$controller, 'listClients']);

    /**
     * @route POST /admin/oauth/clients
     * @summary Create OAuth Client
     * @description Creates a new OAuth client
     * @tag OAuth Administration
     * @security AdminAuth
     * @requestBody name:string="Client name"
     *              description:string="Client description"
     *              redirect_uris:array="List of allowed redirect URIs"
     *              allowed_grant_types:array="List of allowed grant types"
     *              is_confidential:boolean="Whether the client is confidential"
     * @response 201 application/json "Created client" {
     *   client:object="Client object",
     *   client_secret:string="Client secret (shown only once)"
     * }
     */
    Router::post('/clients', [$controller, 'createClient']);

    /**
     * @route GET /admin/oauth/clients/{id}
     * @summary Get OAuth Client
     * @description Retrieves a specific OAuth client
     * @tag OAuth Administration
     * @security AdminAuth
     * @parameter id:string="Client ID" {required=true,in=path}
     * @response 200 application/json "Client information" {
     *   client:object="Client object"
     * }
     * @response 404 application/json "Client not found" { error:string }
     */
    Router::get('/clients/{id}', [$controller, 'getClient']);

    /**
     * @route PUT /admin/oauth/clients/{id}
     * @summary Update OAuth Client
     * @description Updates an existing OAuth client
     * @tag OAuth Administration
     * @security AdminAuth
     * @parameter id:string="Client ID" {required=true,in=path}
     * @requestBody name:string="Client name"
     *              description:string="Client description"
     *              redirect_uris:array="List of allowed redirect URIs"
     *              allowed_grant_types:array="List of allowed grant types"
     *              is_confidential:boolean="Whether the client is confidential"
     * @response 200 application/json "Updated client" {
     *   client:object="Updated client object"
     * }
     * @response 404 application/json "Client not found" { error:string }
     */
    Router::put('/clients/{id}', [$controller, 'updateClient']);

    /**
     * @route DELETE /admin/oauth/clients/{id}
     * @summary Delete OAuth Client
     * @description Deletes an OAuth client
     * @tag OAuth Administration
     * @security AdminAuth
     * @parameter id:string="Client ID" {required=true,in=path}
     * @response 200 application/json "Deleted successfully" { success:boolean }
     * @response 404 application/json "Client not found" { error:string }
     */
    Router::delete('/clients/{id}', [$controller, 'deleteClient']);
}, requiresAdminAuth: true);
