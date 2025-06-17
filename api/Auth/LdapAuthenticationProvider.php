<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Repository\UserRepository;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Auth\BindException;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use LdapRecord\Models\ActiveDirectory\Group as LdapGroup;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Permissions\Helpers\PermissionHelper;

/**
 * LDAP Authentication Provider
 *
 * Implements authentication using LDAP/Active Directory.
 * Supports multiple LDAP servers and group-based authorization.
 */
class LdapAuthenticationProvider implements AuthenticationProviderInterface
{
    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /** @var UserRepository User repository for looking up and creating users */
    private UserRepository $userRepository;

    /** @var array LDAP configuration including server settings */
    private array $ldapConfig;

    /** @var string Current LDAP server ID */
    private string $currentServerId = '';

    /** @var array Map of connection IDs to LdapRecord connection instances */
    private array $connections = [];

    /**
     * Create a new LDAP authentication provider
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->ldapConfig = $this->loadLdapConfiguration();
        $this->initializeConnections();
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        try {
            // Extract username/password from request
            $username = $request->request->get('username') ?? $request->query->get('username');
            $password = $request->request->get('password') ?? $request->query->get('password');

            // Check if we have credentials to authenticate
            if (!$username || !$password) {
                // This is not an LDAP authentication attempt
                return null;
            }

            // Determine which server to authenticate against
            $serverId = $request->request->get('ldap_server') ??
                    $request->query->get('ldap_server') ??
                    $this->getDefaultServerId();

            if (!$this->setCurrentServer($serverId)) {
                $this->lastError = "Invalid LDAP server: {$serverId}";
                return null;
            }

            // Get the connection for the current server
            $connection = $this->getConnection();
            if (!$connection) {
                $this->lastError = "Failed to get LDAP connection for server: {$serverId}";
                return null;
            }

            // Format username if needed
            $formattedUsername = $this->formatUsername($username, $serverId);

            // Authenticate with LDAP
            try {
                if (!$connection->auth()->attempt($formattedUsername, $password)) {
                    $this->lastError = "LDAP authentication failed: Invalid credentials";
                    return null;
                }
            } catch (BindException $e) {
                $this->lastError = "LDAP authentication failed: " . $e->getMessage();
                return null;
            }

            // Get user details from LDAP
            $ldapUser = $this->findLdapUser($username, $serverId);
            if (!$ldapUser) {
                $this->lastError = "User authenticated but not found in LDAP directory";
                return null;
            }

            // Get LDAP attributes and map to user data
            $userData = $this->mapLdapAttributesToUserData($ldapUser, $serverId);
            if (!$userData) {
                $this->lastError = "Failed to map LDAP attributes to user data";
                return null;
            }

            // Find or create user in our system
            $user = $this->userRepository->findOrCreateFromLdap($userData);
            if (!$user) {
                $this->lastError = "Failed to create or retrieve user from LDAP data";
                return null;
            }

            // Store authentication info in request attributes
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $user['uuid'] ?? null);
            $request->attributes->set('user_data', $user);
            $request->attributes->set('auth_method', 'ldap');
            $request->attributes->set('ldap_server', $serverId);

            return $user;
        } catch (\Throwable $e) {
            $this->lastError = "LDAP authentication error: " . $e->getMessage();
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
            ['auth_check' => true, 'provider' => 'ldap']
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

            // Check if it's a valid LDAP token
            if (
                !$payload ||
                !isset($payload['auth_method']) ||
                $payload['auth_method'] !== 'ldap' ||
                !isset($payload['sub']) ||
                !isset($payload['exp'])
            ) {
                $this->lastError = 'Invalid LDAP token format';
                return false;
            }

            // Check if the token has expired
            if ($payload['exp'] < time()) {
                $this->lastError = 'LDAP token has expired';
                return false;
            }

            // Validate the user exists
            $user = $this->userRepository->findByUuid($payload['sub']);
            if (!$user) {
                $this->lastError = 'User not found';
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'LDAP token validation error: ' . $e->getMessage();
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

            // Check if this is an LDAP token based on its structure
            return $payload &&
                   isset($payload['auth_method']) &&
                   $payload['auth_method'] === 'ldap';
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
            // Ensure we have user ID
            if (!isset($userData['uuid'])) {
                $this->lastError = 'Missing required user data';
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
                'name' => $userData['name'] ?? '',
                'email' => $userData['email'] ?? '',
                'auth_method' => 'ldap',
                'ldap_server' => $userData['ldap_server'] ?? $this->currentServerId,
                'iat' => time(),
                'exp' => time() + $accessLifetime
            ];

            // Create refresh token payload (longer lived)
            $refreshPayload = [
                'sub' => $userData['uuid'],
                'auth_method' => 'ldap',
                'ldap_server' => $userData['ldap_server'] ?? $this->currentServerId,
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
            // Validate refresh token format
            if (
                !$payload ||
                !isset($payload['sub']) ||
                !isset($payload['auth_method']) ||
                $payload['auth_method'] !== 'ldap' ||
                !isset($payload['token_type']) ||
                $payload['token_type'] !== 'refresh'
            ) {
                return null;
            }

            // Check if the token has expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->lastError = 'Refresh token has expired';
                return null;
            }

            // Find the user
            $user = $this->userRepository->findByUuid($payload['sub']);
            if (!$user) {
                $this->lastError = 'User not found';
                return null;
            }

            // Add LDAP server info from the refresh token
            if (isset($payload['ldap_server'])) {
                $user['ldap_server'] = $payload['ldap_server'];
            }

            // Generate new tokens
            return $this->generateTokens($user);
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Initialize LDAP connections for all configured servers
     *
     * @return void
     */
    private function initializeConnections(): void
    {
        // Register connections for each server
        foreach ($this->ldapConfig['servers'] as $serverId => $config) {
            // Skip servers with incomplete configuration
            if (empty($config['hosts']) || empty($config['base_dn'])) {
                continue;
            }

            // Create connection configuration
            $connectionConfig = [
                'hosts' => (array)($config['hosts'] ?? []),
                'base_dn' => $config['base_dn'],
                'username' => $config['bind_username'] ?? null,
                'password' => $config['bind_password'] ?? null,
                'port' => $config['port'] ?? 389,
                'use_ssl' => $config['use_ssl'] ?? false,
                'use_tls' => $config['use_tls'] ?? false,
                'timeout' => $config['timeout'] ?? 5,
                'version' => $config['version'] ?? 3,
                'follow_referrals' => $config['follow_referrals'] ?? false,
            ];

            // Add connection to container
            Container::addConnection(
                new Connection($connectionConfig),
                $serverId
            );

            // Remember this connection ID
            $this->connections[] = $serverId;
        }

        // Set default connection if we have at least one
        if (!empty($this->connections)) {
            Container::setDefaultConnection($this->connections[0]);
        }
    }

    /**
     * Set the current LDAP server
     *
     * @param string $serverId Server ID
     * @return bool Whether the server exists in config
     */
    public function setCurrentServer(string $serverId): bool
    {
        if (!isset($this->ldapConfig['servers'][$serverId])) {
            return false;
        }

        $this->currentServerId = $serverId;
        return true;
    }

    /**
     * Get the default LDAP server ID
     *
     * @return string Default server ID
     */
    private function getDefaultServerId(): string
    {
        // If there's only one server, use that
        if (count($this->ldapConfig['servers']) === 1) {
            return array_key_first($this->ldapConfig['servers']);
        }

        // Otherwise use the configured default
        return $this->ldapConfig['default_server'] ?? array_key_first($this->ldapConfig['servers']);
    }

    /**
     * Get current LDAP connection
     *
     * @return Connection|null LDAP connection
     */
    private function getConnection(): ?Connection
    {
        try {
            if (empty($this->currentServerId)) {
                $this->currentServerId = $this->getDefaultServerId();
            }

            return Container::getConnection($this->currentServerId);
        } catch (\Throwable $e) {
            $this->lastError = "LDAP connection error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Format username for LDAP authentication
     *
     * @param string $username Raw username
     * @param string $serverId Server ID
     * @return string Formatted username
     */
    private function formatUsername(string $username, string $serverId): string
    {
        $serverConfig = $this->ldapConfig['servers'][$serverId] ?? [];

        // Apply username format if specified
        if (isset($serverConfig['username_format'])) {
            return str_replace('{username}', $username, $serverConfig['username_format']);
        }

        // Add account suffix if needed and not already present
        if (
            isset($serverConfig['account_suffix']) &&
            !str_ends_with($username, $serverConfig['account_suffix'])
        ) {
            return $username . $serverConfig['account_suffix'];
        }

        return $username;
    }

    /**
     * Find LDAP user
     *
     * @param string $username Username to search for
     * @param string $serverId Server ID
     * @return LdapUser|null LDAP user object
     */
    private function findLdapUser(string $username, string $serverId): ?LdapUser
    {
        try {
            $serverConfig = $this->ldapConfig['servers'][$serverId] ?? [];

            // Set connection for query
            $query = (new LdapUser())->setConnection($serverId);

            // Set search base if specified
            if (!empty($serverConfig['user_search_base'])) {
                $query = $query->in($serverConfig['user_search_base']);
            }

            // Determine attribute to search by
            $searchAttribute = $serverConfig['username_attribute'] ?? 'sAMAccountName';

            // Clean username for search if needed
            $searchUsername = $username;
            if (
                isset($serverConfig['account_suffix']) &&
                str_ends_with($searchUsername, $serverConfig['account_suffix'])
            ) {
                $searchUsername = substr($searchUsername, 0, -strlen($serverConfig['account_suffix']));
            }

            // Try to find the user
            $user = $query->where($searchAttribute, '=', $searchUsername)->first();

            // If not found and using sAMAccountName, try with userPrincipalName
            if (!$user && $searchAttribute === 'sAMAccountName') {
                // If username contains @ it might be a UPN
                if (strpos($username, '@') !== false) {
                    $user = $query->where('userPrincipalName', '=', $username)->first();
                }
            }

            // Explicitly cast to LdapUser or return null to satisfy type declaration
            return $user instanceof LdapUser ? $user : null;
        } catch (\Throwable $e) {
            $this->lastError = "LDAP user lookup error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Map LDAP attributes to user data format
     *
     * @param LdapUser $ldapUser LDAP user object
     * @param string $serverId Server ID
     * @return array|null Mapped user data
     */
    private function mapLdapAttributesToUserData(LdapUser $ldapUser, string $serverId): ?array
    {
        try {
            $serverConfig = $this->ldapConfig['servers'][$serverId] ?? [];
            $attributeMapping = $serverConfig['attribute_mapping'] ?? $this->getDefaultAttributeMapping();

            $userData = [
                'ldap_server' => $serverId,
                'provider' => 'ldap'
            ];

            // Map core attributes
            foreach ($attributeMapping as $userField => $ldapAttribute) {
                $ldapAttributes = (array)$ldapAttribute;

                foreach ($ldapAttributes as $attr) {
                    $value = $ldapUser->getFirstAttribute($attr);
                    if ($value) {
                        $userData[$userField] = $value;
                        break;
                    }
                }
            }

            // Email is required
            if (empty($userData['email'])) {
                // Try to use userPrincipalName as email if it looks like an email
                $upn = $ldapUser->getFirstAttribute('userPrincipalName');
                if ($upn && strpos($upn, '@') !== false) {
                    $userData['email'] = $upn;
                } else {
                    // Try to construct from sAMAccountName + domain suffix
                    $sAMAccountName = $ldapUser->getFirstAttribute('sAMAccountName');
                    if ($sAMAccountName && !empty($serverConfig['account_suffix'])) {
                        $userData['email'] = $sAMAccountName . $serverConfig['account_suffix'];
                    } else {
                        $this->lastError = "Unable to determine email for LDAP user";
                        return null;
                    }
                }
            }

            // Ensure we have a name
            if (empty($userData['name'])) {
                // Try common name attributes
                foreach (['cn', 'displayName', 'name'] as $nameAttr) {
                    $name = $ldapUser->getFirstAttribute($nameAttr);
                    if ($name) {
                        $userData['name'] = $name;
                        break;
                    }
                }

                // Fall back to username
                if (empty($userData['name'])) {
                    $userData['name'] = explode('@', $userData['email'])[0];
                }
            }

            // Get group memberships if configured
            if (!empty($serverConfig['group_mapping'])) {
                $userData['roles'] = $this->getUserRolesFromGroups($ldapUser, $serverId);
            }

            return $userData;
        } catch (\Throwable $e) {
            $this->lastError = "LDAP attribute mapping error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Get user roles from LDAP group memberships
     *
     * @param LdapUser $ldapUser LDAP user object
     * @param string $serverId Server ID
     * @return array User roles
     */
    private function getUserRolesFromGroups(LdapUser $ldapUser, string $serverId): array
    {
        try {
            $serverConfig = $this->ldapConfig['servers'][$serverId] ?? [];
            $groupMapping = $serverConfig['group_mapping'] ?? [];
            $roles = [];

            // Get user groups
            $groups = $ldapUser->groups()->get();

            foreach ($groups as $group) {
                $groupName = $group->getName();
                $groupDn = $group->getDn();

                // Check for direct name mapping
                if (isset($groupMapping[$groupName])) {
                    $roles[] = ['name' => $groupMapping[$groupName]];
                    continue;
                }

                // Check for DN mapping
                if (isset($groupMapping[$groupDn])) {
                    $roles[] = ['name' => $groupMapping[$groupDn]];
                    continue;
                }

                // Check for pattern matching
                foreach ($groupMapping as $pattern => $roleName) {
                    if (substr($pattern, 0, 1) === '/' && preg_match($pattern, $groupName)) {
                        $roles[] = ['name' => $roleName];
                        break;
                    }

                    if (substr($pattern, 0, 1) === '/' && preg_match($pattern, $groupDn)) {
                        $roles[] = ['name' => $roleName];
                        break;
                    }
                }
            }

            return $roles;
        } catch (\Throwable $e) {
            // If there's an error getting groups, log it but continue
            error_log("LDAP group mapping error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get default attribute mapping
     *
     * @return array Default attribute mapping
     */
    private function getDefaultAttributeMapping(): array
    {
        return [
            'email' => ['mail', 'userPrincipalName'],
            'name' => ['displayName', 'cn', 'name'],
            'first_name' => ['givenName'],
            'last_name' => ['sn'],
            'phone' => ['telephoneNumber', 'mobile'],
            'title' => ['title'],
            'department' => ['department'],
            'company' => ['company'],
            'employee_id' => ['employeeID']
        ];
    }

    /**
     * Load LDAP configuration
     *
     * @return array Configuration array
     */
    private function loadLdapConfiguration(): array
    {
        // In a real implementation, this would load from config files
        // This is a placeholder implementation
        return [
            'default_server' => 'company_ad',
            'servers' => [
                'company_ad' => [
                    'hosts' => ['ldap.company.com'],
                    'base_dn' => 'dc=company,dc=com',
                    'bind_username' => 'cn=service-account,ou=service,dc=company,dc=com',
                    'bind_password' => 'secure_password_here',
                    'port' => 389,
                    'use_ssl' => false,
                    'use_tls' => true,
                    'timeout' => 5,
                    'version' => 3,
                    'follow_referrals' => false,
                    'username_attribute' => 'sAMAccountName',
                    'user_search_base' => 'ou=users,dc=company,dc=com',
                    'account_suffix' => '@company.com',
                    'username_format' => '{username}',
                    'attribute_mapping' => [
                        'email' => ['mail', 'userPrincipalName'],
                        'name' => ['displayName', 'cn'],
                        'first_name' => ['givenName'],
                        'last_name' => ['sn'],
                    ],
                    'group_mapping' => [
                        'Domain Admins' => 'superuser',
                        'IT Staff' => 'admin',
                        'Domain Users' => 'user',
                        // Pattern matching for complex group names
                        '/^App-(.+)-Admins$/i' => 'admin',
                    ]
                ],
            ]
        ];
    }
}
