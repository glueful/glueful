<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\DTOs\{UsernameDTO, EmailDTO};
use Glueful\Validation\Validator;
use Glueful\Database\QueryBuilder;

/**
 * User Repository
 *
 * Handles all database operations related to users:
 * - User retrieval by various identifiers
 * - Profile data management
 * - Role association lookups
 * - Password management
 *
 * This repository implements the repository pattern to abstract
 * database operations and provide a clean API for user data access.
 *
 * @package Glueful\Repository
 */
class UserRepository
{
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $queryBuilder;

    /** @var RoleRepository Role repository instance */
    private RoleRepository $roleRepository;

    /** @var Validator Data validator instance */
    private Validator $validator;

    /** @var array Standard user fields to retrieve */
    private array $userFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];

    /** @var array Standard profile fields to retrieve */
    private array $userProfileFields = ['first_name', 'last_name', 'photo_uuid', 'photo_url'];

    /**
     * Initialize repository
     *
     * Sets up database connection and dependencies
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $this->validator = new Validator();

        $this->roleRepository = new RoleRepository();
    }

    /**
     * Find user by username
     *
     * Retrieves user record using the username identifier.
     * Performs validation on the username format before querying.
     *
     * @param string $username Username to search for
     * @return array|null User data or null if not found, or validation errors array
     */
    public function findByUsername(string $username): ?array
    {
        // Validate username format
        $usernameDTO = new UsernameDTO($username);
        $usernameDTO->username = $username;
        if (!$this->validator->validate($usernameDTO)) {
            return $this->validator->errors();
        }

        // Query database for user
        $query = $this->queryBuilder->select('users', $this->userFields)
            ->where(['username' => $username])
            ->limit(1)
            ->get();

        if ($query) {
            return $query[0];
        }

        return null;
    }

    /**
     * Find user by email address
     *
     * Retrieves user record using the email identifier.
     * Performs validation on the email format before querying.
     *
     * @param string $email Email address to search for
     * @return array|null User data or null if not found, or validation errors array
     */
    public function findByEmail(string $email): ?array
    {
        // Validate email format
        $emailDTO = new EmailDTO();
        $emailDTO->email = $email;
        if (!$this->validator->validate($emailDTO)) {
            return $this->validator->errors();
        }

        // Query database for user
        $query = $this->queryBuilder->select('users', $this->userFields)
            ->where(['email' => $email])
            ->limit(1)
            ->get();

        if ($query) {
            return $query[0];
        }

        return null;
    }

    /**
     * Find user by UUID
     *
     * Retrieves user record using the unique identifier.
     * Direct lookup without additional validation.
     *
     * @param string $uuid User UUID to search for
     * @return array|null User data or null if not found
     */
    public function findByUUID(string $uuid): ?array
    {
        $query = $this->queryBuilder->select('users', $this->userFields)
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();

        if ($query) {
            return $query[0];
        }

        return null;
    }

    /**
     * Get user profile data
     *
     * Retrieves extended profile information for a user.
     * Includes personal details and profile images.
     *
     * @param string $uuid User UUID to get profile for
     * @return array|null Profile data or null if not found
     */
    public function getProfile(string $uuid): ?array
    {
        $query = $this->queryBuilder->select('profiles', $this->userProfileFields)
            ->where(['user_uuid' => $uuid])
            ->limit(1)
            ->get();

        if ($query) {
            return $query[0];
        }

        return null;
    }

    /**
     * Get user roles
     *
     * Retrieves all roles assigned to a user.
     * Used for authorization and permission checks.
     *
     * @param string $uuid User UUID to get roles for
     * @return array|null Role data or null if none found
     */
    public function getRoles(string $uuid): ?array
    {
        $query = $this->queryBuilder
        ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid') // Apply JOIN first
        ->select('user_roles_lookup', [
            'user_roles_lookup.user_uuid',
            'user_roles_lookup.role_uuid',
            'roles.uuid AS role_id',
            'roles.name AS role_name'
        ])
        ->where(['user_uuid' => $uuid]) // Ensure column exists
        ->get();

        if ($query) {
            return $query;
        }

        return null;
    }

   /**
     * Update user password
     *
     * Sets a new password for the user identified by email or UUID.
     * The password should already be hashed before calling this method.
     *
     * Security considerations:
     * - Password should be properly hashed using PasswordHasher
     * - Previous sessions should be invalidated after password change
     * - User identity should be verified before allowing password changes
     *
     * @param string $identifier User's email or UUID
     * @param string $password New password (pre-hashed)
     * @param string|null $identifierType Type of identifier ('email' or 'uuid')
     * @return bool Success status
     */
    public function setNewPassword(string $identifier, string $password, ?string $identifierType = null): bool
    {
        // Determine identifier type if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Find the user by the appropriate identifier
        $user = null;
        if ($identifierType === 'email') {
            $user = $this->findByEmail($identifier);
        } else {
            $user = $this->findByUUID($identifier);
        }

        if (!$user) {
            return false; // User not found
        }

        // Update the password using update method
        $affected = $this->queryBuilder->update(
            'users',
            [
                'password' => $password,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            ['uuid' => $user[0]['uuid']]
        );

        return $affected > 0;
    }

    /**
     * Create new user
     *
     * Inserts a new user record with basic information.
     * Additional profile data should be added separately.
     *
     * @param array $userData User data (username, email, password, etc.)
     * @return string|null New user UUID or null on failure
     */
    public function create(array $userData): ?string
    {
        // Ensure required fields are present
        if (!isset($userData['username']) || !isset($userData['email']) || !isset($userData['password'])) {
            return null;
        }

        // Validate username and email
        $usernameDTO = new UsernameDTO($userData['username']);
        $usernameDTO->username = $userData['username'];

        $emailDTO = new EmailDTO();
        $emailDTO->email = $userData['email'];

        if (!$this->validator->validate($usernameDTO) || !$this->validator->validate($emailDTO)) {
            return null;
        }

        // Set default values for optional fields
        $userData['status'] = $userData['status'] ?? 'active';
        $userData['created_at'] = $userData['created_at'] ?? date('Y-m-d H:i:s');

        // Generate UUID if not provided
        if (!isset($userData['uuid'])) {
            $userData['uuid'] = \Glueful\Helpers\Utils::generateNanoID();
        }

        // Insert user record
        $success = $this->queryBuilder->insert('users', $userData);

        return $success ? $userData['uuid'] : null;
    }

    /**
     * Update user information
     *
     * Updates basic user account information.
     * Use this method for username, email, or status changes.
     * For password updates, use setNewPassword() instead.
     *
     * @param string $uuid User UUID to update
     * @param array $userData Updated user data
     * @return bool Success status
     */
    public function update(string $uuid, array $userData): bool
    {
        // Ensure user exists
        $user = $this->findByUUID($uuid);
        if (!$user) {
            return false;
        }

        // Remove fields that shouldn't be updated directly
        unset($userData['password']);
        unset($userData['uuid']);

        // Add updated_at timestamp if not provided
        if (!isset($userData['updated_at'])) {
            $userData['updated_at'] = date('Y-m-d H:i:s');
        }

        // Perform update using the update method with conditions
        $affected = $this->queryBuilder->update(
            'users',
            $userData,
            ['uuid' => $uuid]
        );

        return $affected > 0;
    }

    /**
     * Update user profile
     *
     * Updates or creates user profile information.
     * This includes personal details and preferences.
     *
     * @param string $uuid User UUID
     * @param array $profileData Profile information to update
     * @return bool Success status
     */
    public function updateProfile(string $uuid, array $profileData): bool
    {
        // Ensure user exists
        $user = $this->findByUUID($uuid);
        if (!$user) {
            return false;
        }

        // Add user UUID to profile data
        $profileData['user_uuid'] = $uuid;

        // Check if profile exists
        $existingProfile = $this->getProfile($uuid);

        if ($existingProfile) {
            // Update existing profile
            return $this->queryBuilder->upsert(
                'profiles',
                [$profileData],
                array_keys($profileData)
            ) > 0;
        } else {
            // Create new profile
            $profileData['created_at'] = date('Y-m-d H:i:s');
            return $this->queryBuilder->insert('profiles', $profileData) > 0;
        }
    }

    /**
     * Find user by API key
     *
     * Retrieves user record using the API key identifier.
     * Used for API key-based authentication.
     *
     * @param string $apiKey API key to search for
     * @return array|null User data or null if not found
     */
    public function findByApiKey(string $apiKey): ?array
    {
        // Query database for user with this API key
        $query = $this->queryBuilder->select('users', array_merge($this->userFields, ['api_key', 'api_key_expires_at']))
            ->where(['api_key' => $apiKey])
            ->limit(1)
            ->get();

        if ($query) {
            return $query[0];
        }

        return null;
    }

    /**
     * Find or create a user from SAML authentication data
     *
     * @param array $userData User data extracted from SAML attributes
     * @return array|null User data array or null on failure
     */
    public function findOrCreateFromSaml(array $userData): ?array
    {
        try {
            // Email is required to identify the user
            if (empty($userData['email'])) {
                return null;
            }

            // Try to find the user by email
            $user = $this->findByEmail($userData['email']);

            // If user exists, update SAML-related fields
            if ($user) {
                // Update user with SAML information if needed
                $updates = [
                    'last_login_at' => date('Y-m-d H:i:s'),
                    'provider' => 'saml',
                    'provider_id' => $userData['saml_idp'] ?? null
                ];

                // Optionally update name if provided
                if (!empty($userData['name'])) {
                    $updates['name'] = $userData['name'];
                }

                // Optionally update first/last name if provided
                if (!empty($userData['first_name'])) {
                    $updates['first_name'] = $userData['first_name'];
                }

                if (!empty($userData['last_name'])) {
                    $updates['last_name'] = $userData['last_name'];
                }

                $this->update($user['uuid'], $updates);

                // Reload the user to get updated data
                $user = $this->findByUUId($user['uuid']);

                // Sync roles if available
                if (!empty($userData['roles'])) {
                    $this->syncUserRoles($user['uuid'], $userData['roles']);
                }
                return $user;
            }
            // User doesn't exist, create a new one
            $newUser = [
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'email' => $userData['email'],
                'name' => $userData['name'] ?? explode('@', $userData['email'])[0],
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), // Random password
                'provider' => 'saml',
                'provider_id' => $userData['saml_idp'] ?? null,
                'email_verified_at' => date('Y-m-d H:i:s'), // SAML users are pre-verified
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_login_at' => date('Y-m-d H:i:s')
            ];

            // Create the new user
            $userId = $this->create($newUser);
            if (!$userId) {
                return null;
            }

            // Assign default roles for new SAML users
            $defaultRoles = !empty($userData['roles']) ? $userData['roles'] : [['name' => 'user']];
            $this->syncUserRoles($newUser['uuid'], $defaultRoles);

            // Return the newly created user
            return $this->findByUUId($newUser['uuid']);
        } catch (\Throwable $e) {
            // Log the error
            error_log('Error in findOrCreateFromSaml: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sync user roles with the provided role array
     *
     * @param string $userUuid User UUID
     * @param array $roles Array of role data
     * @return bool Success
     */
    private function syncUserRoles(string $userUuid, array $roles): bool
    {
        try {
            // Delete existing roles for this user
            $this->queryBuilder->delete('user_roles_lookup', ['user_uuid' => $userUuid]);

            // No roles to add
            if (empty($roles)) {
                return true;
            }

            // Prepare role data for insertion
            $rolesToInsert = [];

            foreach ($roles as $role) {
                if (!isset($role['name'])) {
                    continue;
                }

                // Get role UUID from role name
                $roleUuid = $this->roleRepository->getRoleUuidByName($role['name']);
                if (!$roleUuid) {
                    continue;
                }

                // Create a record for insertion
                $rolesToInsert[] = [
                    'user_uuid' => $userUuid,
                    'role_uuid' => $roleUuid,
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }

            if (empty($rolesToInsert)) {
                return true;
            }

            // Use batch insert for better performance
            $result = $this->queryBuilder->insert('user_roles_lookup', $rolesToInsert);

            return $result > 0;
        } catch (\Throwable $e) {
            error_log('Error in syncUserRoles: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find or create a user from LDAP authentication data
     *
     * @param array $userData User data extracted from LDAP attributes
     * @return array|null User data array or null on failure
     */
    public function findOrCreateFromLdap(array $userData): ?array
    {
        try {
            // Email is required to identify the user
            if (empty($userData['email'])) {
                return null;
            }

            // Try to find the user by email
            $user = $this->findByEmail($userData['email']);

            // If user exists, update LDAP-related fields
            if ($user) {
                // Update user with LDAP information
                $updates = [
                    'last_login_at' => date('Y-m-d H:i:s'),
                    'provider' => 'ldap',
                    'provider_id' => $userData['ldap_server'] ?? null
                ];

                // Update name if provided
                if (!empty($userData['name'])) {
                    $updates['name'] = $userData['name'];
                }

                // Update first/last name if provided
                if (!empty($userData['first_name'])) {
                    $updates['first_name'] = $userData['first_name'];
                }

                if (!empty($userData['last_name'])) {
                    $updates['last_name'] = $userData['last_name'];
                }

                // Update additional fields if they exist
                foreach (['phone', 'title', 'department', 'company', 'employee_id'] as $field) {
                    if (!empty($userData[$field])) {
                        $updates[$field] = $userData[$field];
                    }
                }

                $this->update($user['uuid'], $updates);

                // Reload the user to get updated data
                $user = $this->findByUUID($user['uuid']);

                // Sync roles if available
                if (!empty($userData['roles'])) {
                    $this->syncUserRoles($user['uuid'], $userData['roles']);

                    // Reload roles for the user
                    $user['roles'] = $this->roleRepository->getUserRoles($user['uuid']);
                }

                return $user;
            }

            // User doesn't exist, create a new one
            $newUser = [
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'email' => $userData['email'],
                'name' => $userData['name'] ?? explode('@', $userData['email'])[0],
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'phone' => $userData['phone'] ?? null,
                'title' => $userData['title'] ?? null,
                'department' => $userData['department'] ?? null,
                'company' => $userData['company'] ?? null,
                'employee_id' => $userData['employee_id'] ?? null,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), // Random password
                'provider' => 'ldap',
                'provider_id' => $userData['ldap_server'] ?? null,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'), // LDAP users are pre-verified
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_login_at' => date('Y-m-d H:i:s')
            ];

            // Create the new user
            $this->queryBuilder->insert('users', array_filter($newUser, function ($value) {
                return $value !== null;
            }));

            // Assign default roles for new LDAP users
            $defaultRoles = !empty($userData['roles']) ? $userData['roles'] : [['name' => 'user']];
            $this->syncUserRoles($newUser['uuid'], $defaultRoles);

            // Return the newly created user with roles
            $user = $this->findByUUID($newUser['uuid']);
            $user['roles'] = $this->roleRepository->getUserRoles($newUser['uuid']);

            return $user;
        } catch (\Throwable $e) {
            // Log the error
            error_log('Error in findOrCreateFromLdap: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the currently authenticated user
     *
     * Retrieves the current user directly from the session cache using the token in the request.
     * This avoids re-authenticating the request and is more efficient.
     *
     * @return array|null User data or null if not authenticated
     */
    public function getCurrentUser(): ?array
    {
        try {
            // Get current request
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

            // Extract token from the Authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader) {
                return null;
            }

            // Remove 'Bearer ' prefix if present
            $token = (strpos($authHeader, 'Bearer ') === 0) ? substr($authHeader, 7) : $authHeader;

            if (empty($token)) {
                return null;
            }

            // Get session data directly from the session cache
            $userData = \Glueful\Auth\SessionCacheManager::getSession($token);

            if ($userData && isset($userData['uuid'])) {
                // If we just have a basic user record with UUID, get the full user data
                if (count($userData) <= 2) {
                    return $this->findByUUID($userData['uuid']);
                }
                return $userData;
            }
        } catch (\Throwable $e) {
            // Silently handle auth errors - logging should continue to work
            // even if authentication fails
        }
        return null;
    }
}
