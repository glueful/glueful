<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\DTOs\{UsernameDTO, EmailDTO};
use Glueful\Validation\Validator;
use Glueful\Helpers\Utils;

/**
 * User Repository
 *
 * Handles all database operations related to users:
 * - User retrieval by various identifiers
 * - Profile data management
 * - Role association lookups
 * - Password management
 *
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality.
 *
 * @package Glueful\Repository
 */
class UserRepository extends BaseRepository
{
    /** @var RoleRepository Role repository instance */
    private RoleRepository $roleRepository;

    /** @var Validator Data validator instance */
    private Validator $validator;

    /** @var array Standard profile fields to retrieve */
    private array $userProfileFields = ['first_name', 'last_name', 'photo_uuid', 'photo_url'];

    /**
     * Initialize repository
     *
     * Sets up database connection and dependencies
     */
    public function __construct()
    {
        // Set the table and other configuration before calling parent constructor
        $this->table = 'users';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];
        $this->containsSensitiveData = true;
        $this->sensitiveFields = ['password', 'api_key', 'remember_token', 'reset_token'];

        // Call parent constructor to set up database connection
        parent::__construct();

        // Initialize additional dependencies
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

        // Use BaseRepository's findBy method
        return $this->findBy('username', $username);
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

        // Use BaseRepository's findBy method
        return $this->findBy('email', $email);
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
        // Use BaseRepository's findById method since UUID is our primary key
        return $this->findBy($this->primaryKey, $uuid);
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
        // Create a query but use a different table than the default one
        $query = $this->db->select('profiles', $this->userProfileFields)
            ->where(['user_uuid' => $uuid])
            ->limit(1)
            ->get();

        return $query ? $query[0] : null;
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
        $query = $this->db
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid') // Apply JOIN first
            ->select('user_roles_lookup', [
                'user_roles_lookup.user_uuid',
                'user_roles_lookup.role_uuid',
                'roles.uuid AS role_id',
                'roles.name AS role_name'
            ])
            ->where(['user_uuid' => $uuid]) // Ensure column exists
            ->get();

        return $query ?: null;
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

        // Get the currently authenticated user if available
        $currentUser = $this->getCurrentUser();
        $userId = $currentUser['uuid'] ?? null;

        // Update just the password field using the parent update method
        // The BaseRepository.auditDataAction method will automatically handle audit logging
        $success = $this->update($user['uuid'], [
            'password' => $password
        ], $userId);

        return $success;
    }

    /**
     * Create new user
     *
     * Inserts a new user record with basic information.
     * Additional profile data should be added separately.
     *
     * @param array $userData User data (username, email, password, etc.)
     * @param string|null $createdByUserId UUID of user creating this user (for audit)
     * @return string|null New user UUID or null on failure
     */
    public function create(array $userData, ?string $createdByUserId = null): ?string
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

        // Generate UUID if not provided
        if (!isset($userData['uuid'])) {
            $userData['uuid'] = Utils::generateNanoID();
        }

        // Get current user ID for audit if not provided
        if (!$createdByUserId) {
            $currentUser = $this->getCurrentUser();
            $createdByUserId = $currentUser['uuid'] ?? null;
        }

        // Use parent create method which handles insertion and audit logging
        $result = parent::create($userData, $createdByUserId);

        return $result ? (string)$userData['uuid'] : null;
    }

    /**
     * Update user information
     *
     * Updates basic user account information.
     * Use this method for username, email, or status changes.
     * For password updates, use setNewPassword() instead.
     *
     * @param string|int $id User UUID to update
     * @param array $userData Updated user data
     * @param string|null $updatedByUserId UUID of user making the update (for audit)
     * @return bool Success status
     */
    public function update($id, array $userData, ?string $updatedByUserId = null): bool
    {
        // Ensure user exists
        $user = $this->findByUUID($id);
        if (!$user) {
            return false;
        }

        // Remove fields that shouldn't be updated directly
        unset($userData['password']); // Use setNewPassword for password changes
        unset($userData['uuid']);     // Primary key shouldn't be changed

        // Get current user ID for audit if not provided
        if (!$updatedByUserId) {
            $currentUser = $this->getCurrentUser();
            $updatedByUserId = $currentUser['uuid'] ?? null;
        }

        // Use parent update method which handles update and audit logging
        return parent::update($id, $userData, $updatedByUserId);
    }

    /**
     * Update user profile
     *
     * Updates or creates user profile information.
     * This includes personal details and preferences.
     *
     * @param string $uuid User UUID
     * @param array $profileData Profile information to update
     * @param string|null $updatedByUserId UUID of user making the update (for audit)
     * @return bool Success status
     */
    public function updateProfile(string $uuid, array $profileData, ?string $updatedByUserId = null): bool
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

        // Get current user ID for audit if not provided
        if ($updatedByUserId === null) {
            $currentUser = $this->getCurrentUser();
            $updatedByUserId = $currentUser['uuid'] ?? null;
        }

        $success = false;
        $oldTable = $this->table;
        $oldPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change the table for this operation
            $this->table = 'profiles';
            $this->primaryKey = 'user_uuid';

            if ($existingProfile) {
                // Update existing profile
                $success = parent::update($uuid, $profileData, $updatedByUserId);
            } else {
                // Create new profile
                $success = parent::create($profileData, $updatedByUserId) !== null;
            }
        } finally {
            // Restore the original table and primary key
            $this->table = $oldTable;
            $this->primaryKey = $oldPrimaryKey;
        }

        return $success;
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
        // Use BaseRepository's findBy method since we're still querying the users table
        return $this->findBy('api_key', $apiKey);
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

            // Create the new user using the parent create method
            // This will also handle the audit logging
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
            $connection = new \Glueful\Database\Connection();
            $queryBuilder = new \Glueful\Database\QueryBuilder($connection->getPDO(), $connection->getDriver());
            // Delete existing roles for this user
            $queryBuilder->delete('user_roles_lookup', ['user_uuid' => $userUuid]);

            // Prepare role data for insertion
            $rolesToInsert = [];
            $roleNames = [];

            foreach ($roles as $role) {
                if (!isset($role['name'])) {
                    continue;
                }

                // Get role UUID from role name
                $roleUuid = $this->roleRepository->getRoleUuidByName($role['name']);
                if (!$roleUuid) {
                    continue;
                }

                $roleNames[] = $role['name'];

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
            $result = $queryBuilder->insert('user_roles_lookup', $rolesToInsert);
            $success = $result > 0;

            return $success;
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
            $connection = new \Glueful\Database\Connection();
            $queryBuilder = new \Glueful\Database\QueryBuilder($connection->getPDO(), $connection->getDriver());

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
            $queryBuilder->insert('users', array_filter($newUser, function ($value) {
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
