<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Repository\UserRepository;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Constants as SamlConstants;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Permissions\Helpers\PermissionHelper;

/**
 * SAML Authentication Provider
 *
 * Implements authentication using SAML 2.0 protocol for enterprise single sign-on.
 * Integrates with identity providers like Azure AD, Okta, Google Workspace, etc.
 */
class SamlAuthenticationProvider implements AuthenticationProviderInterface
{
    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /** @var UserRepository User repository for looking up and creating users */
    private UserRepository $userRepository;

    /** @var array SAML configuration including IdP settings */
    private array $samlConfig;

    /** @var SamlAuth|null SAML authentication object */
    private ?SamlAuth $samlAuth = null;

    /** @var string Current identity provider ID */
    private string $currentIdpId = '';

    /**
     * Create a new SAML authentication provider
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->samlConfig = $this->loadSamlConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        try {
            // Check if this is a SAML response
            if (!$request->request->has('SAMLResponse') && !$request->query->has('SAMLResponse')) {
                // This is not a SAML authentication attempt
                return null;
            }

            // Determine which IdP this response is for
            $idpId = $request->attributes->get('idp') ??
                     $request->query->get('idp') ??
                     $this->getDefaultIdpId();

            if (!$this->setCurrentIdp($idpId)) {
                $this->lastError = "Invalid identity provider: {$idpId}";
                return null;
            }

            // Initialize SAML Auth
            $this->initializeSamlAuth($request);

            // Process SAML response
            $this->samlAuth->processResponse();

            // Check for errors
            $errors = $this->samlAuth->getErrors();
            if (!empty($errors)) {
                $this->lastError = 'SAML authentication failed: ' . implode(', ', $errors);
                return null;
            }

            // Check authentication status
            if (!$this->samlAuth->isAuthenticated()) {
                $this->lastError = 'SAML authentication failed: Not authenticated';
                return null;
            }

            // Extract user attributes from SAML assertion
            $attributes = $this->samlAuth->getAttributes();
            $nameId = $this->samlAuth->getNameId();
            $sessionIndex = $this->samlAuth->getSessionIndex();

            // Map SAML attributes to user data
            $userData = $this->mapSamlAttributesToUserData($attributes, $nameId, $idpId);
            if (!$userData) {
                $this->lastError = 'Failed to map SAML attributes to user data';
                return null;
            }

            // Find or create the user in our system
            $user = $this->userRepository->findOrCreateFromSaml($userData);
            if (!$user) {
                $this->lastError = 'Failed to create or retrieve user from SAML data';
                return null;
            }

            // Store SAML session information
            $user['saml_session_index'] = $sessionIndex;
            $user['saml_name_id'] = $nameId;
            $user['saml_idp'] = $idpId;

            // Store authentication info in request attributes
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $user['uuid'] ?? null);
            $request->attributes->set('user_data', $user);
            $request->attributes->set('auth_method', 'saml');

            return $user;
        } catch (\Throwable $e) {
            $this->lastError = 'SAML authentication error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAdmin(array $userData): bool
    {
        $user = $userData['user'] ?? $userData;

        // Fallback to is_admin flag if no UUID available
        if (!isset($user['uuid'])) {
            return !empty($user['is_admin']);
        }

        // Check if permission system is available
        if (!PermissionHelper::isAvailable()) {
            // Fall back to is_admin flag
            return !empty($user['is_admin']);
        }

        // Check if user has admin access using PermissionHelper
        $hasAdminAccess = PermissionHelper::canAccessAdmin(
            $user['uuid'],
            ['auth_check' => true, 'provider' => 'saml']
        );

        // If permission check fails, fall back to is_admin flag as safety net
        if (!$hasAdminAccess && !empty($user['is_admin'])) {
            error_log("Admin permission check failed for user {$user['uuid']}, falling back to is_admin flag");
            return true;
        }

        return $hasAdminAccess;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * {@inheritdoc}
     */
    public function validateToken(string $token): bool
    {
        try {
            // Decode the token
            $payload = json_decode(base64_decode($token), true);

            // Check if it's a valid SAML token
            if (
                !$payload ||
                !isset($payload['saml_name_id']) ||
                !isset($payload['saml_idp']) ||
                !isset($payload['exp'])
            ) {
                $this->lastError = 'Invalid SAML token format';
                return false;
            }

            // Check if the token has expired
            if ($payload['exp'] < time()) {
                $this->lastError = 'SAML token has expired';
                return false;
            }
            // Validate the user exists
            $user = $this->userRepository->findByUUID($payload['sub']);
            if (!$user) {
                $this->lastError = 'User not found';
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'SAML token validation error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        try {
            $payload = json_decode(base64_decode($token), true);

            // Check if this is a SAML token based on its structure
            return $payload &&
                isset($payload['auth_method']) &&
                $payload['auth_method'] === 'saml' &&
                isset($payload['saml_name_id']) &&
                isset($payload['saml_idp']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        try {
            // Ensure we have SAML session data
            if (
                !isset($userData['saml_name_id']) ||
                !isset($userData['saml_idp']) ||
                !isset($userData['uuid'])
            ) {
                $this->lastError = 'Missing required SAML session data';
                return [
                    'access_token' => '',
                    'refresh_token' => '',
                    'expires_in' => 0
                ];
            }

            // Default token lifetimes
            $accessLifetime = $accessTokenLifetime ?? (8 * 3600); // 8 hours
            $refreshLifetime = $refreshTokenLifetime ?? (30 * 24 * 3600); // 30 days

            // Create access token payload
            $accessPayload = [
                'sub' => $userData['uuid'],
                'name_id' => $userData['saml_name_id'],
                'saml_idp' => $userData['saml_idp'],
                'saml_name_id' => $userData['saml_name_id'],
                'saml_session_index' => $userData['saml_session_index'] ?? null,
                'auth_method' => 'saml',
                'iat' => time(),
                'exp' => time() + $accessLifetime
            ];

            // Create refresh token payload (longer lived)
            $refreshPayload = [
                'sub' => $userData['uuid'],
                'saml_idp' => $userData['saml_idp'],
                'saml_name_id' => $userData['saml_name_id'],
                'auth_method' => 'saml',
                'token_type' => 'refresh',
                'iat' => time(),
                'exp' => time() + $refreshLifetime
            ];

            return [
                'access_token' => base64_encode(json_encode($accessPayload)),
                'refresh_token' => base64_encode(json_encode($refreshPayload)),
                'expires_in' => $accessLifetime
            ];
        } catch (\Throwable $e) {
            $this->lastError = 'Token generation error: ' . $e->getMessage();
            return [
                'access_token' => '',
                'refresh_token' => '',
                'expires_in' => 0
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        try {
            // Decode refresh token
            $payload = json_decode(base64_decode($refreshToken), true);

            // Validate refresh token format
            if (
                !$payload ||
                !isset($payload['sub']) ||
                !isset($payload['saml_idp']) ||
                !isset($payload['saml_name_id']) ||
                !isset($payload['token_type']) ||
                $payload['token_type'] !== 'refresh'
            ) {
                $this->lastError = 'Invalid refresh token format';
                return null;
            }

            // Check if the token has expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->lastError = 'Refresh token has expired';
                return null;
            }

            // Find the user
            $user = $this->userRepository->findByUUID($payload['sub']);
            if (!$user) {
                $this->lastError = 'User not found';
                return null;
            }

            // Add required SAML fields from refresh token to the user data
            $user['saml_idp'] = $payload['saml_idp'];
            $user['saml_name_id'] = $payload['saml_name_id'];
            $user['saml_session_index'] = $sessionData['saml_session_index'] ?? null;

            // Generate new tokens
            return $this->generateTokens($user);
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Initialize SAML Auth instance
     *
     * @param Request $request The HTTP request
     * @return void
     * @throws \Exception If SAML initialization fails
     */
    private function initializeSamlAuth(Request $request): void
    {
        // Ensure we have a current IdP set
        if (empty($this->currentIdpId)) {
            throw new \Exception('No Identity Provider selected');
        }

        // Get SAML settings for the current IdP
        $settings = $this->getSamlSettings($request);

        // Create SAML Auth instance
        $this->samlAuth = new SamlAuth($settings);
    }

    /**
     * Generate SAML settings for the current IdP
     *
     * @param Request $request Current request for URL generation
     * @return array SAML settings array
     */
    private function getSamlSettings(Request $request): array
    {
        // Get configuration for current IdP
        $idpConfig = $this->samlConfig['providers'][$this->currentIdpId] ?? null;
        if (!$idpConfig) {
            throw new \Exception("Configuration for IdP '{$this->currentIdpId}' not found");
        }

        // Build the base URL for routes
        $baseUrl = $request->getSchemeAndHttpHost();

        // Build SAML settings
        $settings = [
            'strict' => true,
            'debug' => $this->samlConfig['debug'] ?? false,
            'sp' => [
                'entityId' => $idpConfig['sp']['entity_id'] ?? "{$baseUrl}/saml/{$this->currentIdpId}/metadata",
                'assertionConsumerService' => [
                    'url' => $idpConfig['sp']['acs_url'] ?? "{$baseUrl}/saml/{$this->currentIdpId}/acs",
                    'binding' => SamlConstants::BINDING_HTTP_POST,
                ],
                'singleLogoutService' => [
                    'url' => $idpConfig['sp']['sls_url'] ?? "{$baseUrl}/saml/{$this->currentIdpId}/sls",
                    'binding' => SamlConstants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => $idpConfig['sp']['x509cert'] ?? '',
                'privateKey' => $idpConfig['sp']['private_key'] ?? '',
            ],
            'idp' => [
                'entityId' => $idpConfig['idp']['entity_id'],
                'singleSignOnService' => [
                    'url' => $idpConfig['idp']['sso_url'],
                    'binding' => SamlConstants::BINDING_HTTP_REDIRECT,
                ],
                'singleLogoutService' => [
                    'url' => $idpConfig['idp']['slo_url'] ?? null,
                    'binding' => SamlConstants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => $idpConfig['idp']['x509cert'],
            ],
            'security' => [
                'nameIdEncrypted' => $idpConfig['security']['name_id_encrypted'] ?? false,
                'authnRequestsSigned' => $idpConfig['security']['authn_requests_signed'] ?? false,
                'logoutRequestSigned' => $idpConfig['security']['logout_request_signed'] ?? false,
                'logoutResponseSigned' => $idpConfig['security']['logout_response_signed'] ?? false,
                'wantMessagesSigned' => $idpConfig['security']['want_messages_signed'] ?? false,
                'wantAssertionsSigned' => $idpConfig['security']['want_assertions_signed'] ?? true,
                'wantAssertionsEncrypted' => $idpConfig['security']['want_assertions_encrypted'] ?? false,
                'wantNameIdEncrypted' => $idpConfig['security']['want_name_id_encrypted'] ?? false,
            ]
        ];

        return $settings;
    }

    /**
     * Map SAML attributes to user data format
     *
     * @param array $attributes SAML attributes from assertion
     * @param string $nameId User's SAML NameID
     * @param string $idpId Identity provider ID
     * @return array|null Mapped user data
     */
    private function mapSamlAttributesToUserData(array $attributes, string $nameId, string $idpId): ?array
    {
        // Get attribute mapping for the IdP
        $idpConfig = $this->samlConfig['providers'][$idpId] ?? null;
        if (!$idpConfig) {
            return null;
        }

        $mapping = $idpConfig['attribute_mapping'] ?? $this->getDefaultAttributeMapping();
        $userData = [
            'saml_name_id' => $nameId,
            'saml_idp' => $idpId
        ];

    // Extract email (required)
        $userData['email'] = $this->extractFirstMappedAttribute(
            $attributes,
            $mapping['email'] ?? ['email', 'mail', 'emailaddress']
        );
        if (empty($userData['email'])) {
            // If no explicit email attribute, try to use nameId if it looks like an email
            if (filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
                $userData['email'] = $nameId;
            } else {
                $this->lastError = 'Email address not found in SAML attributes';
                return null;
            }
        }

        // Extract name
        $userData['name'] = $this->extractFirstMappedAttribute(
            $attributes,
            $mapping['name'] ?? ['name', 'displayname', 'cn']
        );

        // If no name found, use part of email
        if (empty($userData['name'])) {
            $userData['name'] = explode('@', $userData['email'])[0];
        }

        // Extract first and last names if available
        $userData['first_name'] = $this->extractFirstMappedAttribute(
            $attributes,
            $mapping['first_name'] ?? ['givenname', 'firstname', 'given_name']
        );

        $userData['last_name'] = $this->extractFirstMappedAttribute(
            $attributes,
            $mapping['last_name'] ?? ['surname', 'lastname', 'sn', 'family_name']
        );

        // Extract roles/groups and map to system roles if configuration exists
        $roleValues = $this->extractMappedAttributes(
            $attributes,
            $mapping['roles'] ?? ['roles', 'groups', 'memberof']
        );

        if (!empty($roleValues) && isset($idpConfig['role_mapping'])) {
            $userData['roles'] = $this->mapSamlRolesToSystemRoles($roleValues, $idpConfig['role_mapping']);
        }

        return $userData;
    }

    /**
     * Get the first attribute value from mapped SAML attributes
     *
     * @param array $attributes SAML attributes
     * @param array|string $mappedNames Possible attribute names
     * @return string|null First found attribute value or null
     */
    private function extractFirstMappedAttribute(array $attributes, $mappedNames): ?string
    {
        $mappedNames = (array) $mappedNames;

        foreach ($mappedNames as $name) {
            if (isset($attributes[$name]) && !empty($attributes[$name][0])) {
                return $attributes[$name][0];
            }
        }

        return null;
    }

    /**
     * Extract all values for mapped SAML attributes
     *
     * @param array $attributes SAML attributes
     * @param array|string $mappedNames Possible attribute names
     * @return array All found attribute values
     */
    private function extractMappedAttributes(array $attributes, $mappedNames): array
    {
        $mappedNames = (array) $mappedNames;
        $values = [];

        foreach ($mappedNames as $name) {
            if (isset($attributes[$name]) && is_array($attributes[$name])) {
                $values = array_merge($values, $attributes[$name]);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Map SAML roles to system roles using configured mapping
     *
     * @param array $samlRoles Roles from SAML assertion
     * @param array $roleMapping Mapping configuration
     * @return array System roles
     */
    private function mapSamlRolesToSystemRoles(array $samlRoles, array $roleMapping): array
    {
        $systemRoles = [];

        foreach ($samlRoles as $role) {
            // Check for direct mapping
            if (isset($roleMapping[$role])) {
                $systemRoles[] = [
                    'name' => $roleMapping[$role]
                ];
                continue;
            }

            // Check for pattern matching (e.g., for AD groups like "CN=GroupName,DC=example,DC=com")
            foreach ($roleMapping as $pattern => $systemRole) {
                if (substr($pattern, 0, 1) === '/' && preg_match($pattern, $role)) {
                    $systemRoles[] = [
                        'name' => $systemRole
                    ];
                    break;
                }
            }
        }

        return $systemRoles;
    }

    /**
     * Set the current identity provider
     *
     * @param string $idpId Identity provider ID
     * @return bool Whether the IdP exists
     */
    public function setCurrentIdp(string $idpId): bool
    {
        if (!isset($this->samlConfig['providers'][$idpId])) {
            return false;
        }

        $this->currentIdpId = $idpId;
        return true;
    }

    /**
     * Get the default identity provider ID
     *
     * @return string Default IdP ID
     */
    private function getDefaultIdpId(): string
    {
        // If there's only one IdP, use that
        if (count($this->samlConfig['providers']) === 1) {
            return array_key_first($this->samlConfig['providers']);
        }

        // Otherwise use the configured default
        return $this->samlConfig['default_idp'] ?? array_key_first($this->samlConfig['providers']);
    }

    /**
     * Load SAML configuration
     *
     * @return array Configuration array
     */
    private function loadSamlConfiguration(): array
    {
        // In a real implementation, this would load from config files
        // This is a placeholder implementation
        return [
            'debug' => false,
            'default_idp' => 'azure_ad',
            'providers' => [
                'azure_ad' => [
                    'idp' => [
                        'entity_id' => 'https://sts.windows.net/tenant-id/',
                        'sso_url' => 'https://login.microsoftonline.com/tenant-id/saml2',
                        'slo_url' => 'https://login.microsoftonline.com/tenant-id/saml2',
                        'x509cert' => '-----BEGIN CERTIFICATE-----...-----END CERTIFICATE-----',
                    ],
                    'sp' => [],
                    'attribute_mapping' => [
                        'email' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'],
                        'name' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'],
                        'first_name' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'],
                        'last_name' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'],
                        'roles' => ['http://schemas.microsoft.com/ws/2008/06/identity/claims/role']
                    ],
                    'role_mapping' => [
                        'Admin' => 'superuser',
                        'User' => 'user',
                    ],
                    'security' => [
                        'want_assertions_signed' => true,
                    ]
                ],
                // Additional IdP configurations would go here
            ]
        ];
    }

    /**
     * Get default attribute mapping
     *
     * @return array Default attribute mapping
     */
    private function getDefaultAttributeMapping(): array
    {
        return [
            'email' => ['email', 'mail', 'emailaddress',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'],
            'name' => ['name', 'displayname', 'cn', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'],
            'first_name' => ['givenname', 'firstname', 'given_name',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'],
            'last_name' => ['surname', 'lastname', 'sn',
            'family_name', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'],
            'roles' => ['roles', 'groups', 'memberof', 'http://schemas.microsoft.com/ws/2008/06/identity/claims/role']
        ];
    }

    /**
     * Generate SP metadata for an identity provider
     *
     * @param string $idpId Identity provider ID
     * @param Request $request Current request
     * @return string XML metadata
     * @throws \Exception On metadata generation error
     */
    public function generateSpMetadata(string $idpId, Request $request): string
    {
        if (!$this->setCurrentIdp($idpId)) {
            throw new \Exception("Identity provider '{$idpId}' not found");
        }

        $this->initializeSamlAuth($request);
        $settings = $this->samlAuth->getSettings();
        $metadata = $settings->getSPMetadata();

        $errors = $settings->validateMetadata($metadata);
        if (!empty($errors)) {
            throw new \Exception('Invalid metadata: ' . implode(', ', $errors));
        }

        return $metadata;
    }

    /**
     * Initiate SAML login flow
     *
     * @param string $idpId Identity provider ID
     * @param Request $request Current request
     * @param string|null $returnTo URL to return to after authentication
     * @return string URL for redirect
     * @throws \Exception On login initiation error
     */
    public function login(string $idpId, Request $request, ?string $returnTo = null): string
    {
        if (!$this->setCurrentIdp($idpId)) {
            throw new \Exception("Identity provider '{$idpId}' not found");
        }

        $this->initializeSamlAuth($request);
        return $this->samlAuth->login($returnTo, [], false, false, true);
    }

    /**
     * Initiate SAML logout flow
     *
     * @param string $idpId Identity provider ID
     * @param Request $request Current request
     * @param string|null $returnTo URL to return to after logout
     * @param string|null $nameId User's SAML NameID
     * @param string|null $sessionIndex User's SAML session index
     * @return string|null URL for redirect, or null if logout is not supported
     * @throws \Exception On logout initiation error
     */
    public function logout(
        string $idpId,
        Request $request,
        ?string $returnTo = null,
        ?string $nameId = null,
        ?string $sessionIndex = null
    ): ?string {
        if (!$this->setCurrentIdp($idpId)) {
            throw new \Exception("Identity provider '{$idpId}' not found");
        }

        $this->initializeSamlAuth($request);

        // We don't need to explicitly set nameId and sessionIndex
        // as they can be passed directly to the logout method
        return $this->samlAuth->logout($returnTo, [], $nameId, $sessionIndex);
    }
}
