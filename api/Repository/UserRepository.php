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
class UserRepository {
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $queryBuilder;
    
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
    public function __construct() {
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $this->validator = new Validator();
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
    public function findByUsername(string $username): ?array {
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
    public function findByEmail(string $email): ?array {
        // Validate email format
        $emailDTO = new EmailDTO($email);
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
    public function findByUUID(string $uuid): ?array {
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
    public function getProfile(string $uuid): ?array {
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
    public function getRoles(string $uuid): ?array {
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
    public function setNewPassword(string $identifier, string $password, ?string $identifierType = null): bool {
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
        
        if (!$user || empty($user)) {
            return false; // User not found
        }
        
        // Format data for upsert - needs to be an array of records
        $data = [
            [
                'uuid' => $user[0]['uuid'],
                'password' => $password,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];
        
        // Specify which columns should be updated on duplicate
        $updateColumns = ['password', 'updated_at'];
        
        // Perform the upsert operation
        $affected = $this->queryBuilder->upsert('users', $data, $updateColumns);
        
        // Return true if at least one record was affected
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
    public function create(array $userData): ?string {
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
    public function update(string $uuid, array $userData): bool {
        // Ensure user exists
        $user = $this->findByUUID($uuid);
        if (!$user) {
            return false;
        }
        
        // Remove fields that shouldn't be updated directly
        unset($userData['password']);
        unset($userData['uuid']);
        
        
        // Format data for upsert
        $data = [array_merge(['uuid' => $uuid], $userData)];
        
        // Perform update
        $affected = $this->queryBuilder->upsert(
            'users', 
            $data, 
            array_keys($userData)
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
    public function updateProfile(string $uuid, array $profileData): bool {
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
}
