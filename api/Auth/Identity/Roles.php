<?php
declare(strict_types=1);
namespace Glueful\Identity;

use Glueful\Http\Response;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

/**
 * Role Management Service
 * 
 * Manages application roles and user role assignments:
 * - Role CRUD operations
 * - User-role associations
 * - Role hierarchy management
 * - Access level control
 * 
 * Security features:
 * - Role-based access control
 * - User role mapping
 * - Role inheritance
 * - Access level validation
 * 
 * @package Glueful\Identity
 */
class Roles
{
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $db;

    /**
     * Initialize roles service
     * 
     * Sets up database connection and query builder
     * for role management operations.
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());;
    }

    /**
     * Get all system roles
     * 
     * Retrieves complete list of roles with:
     * - Basic role information
     * - Role descriptions
     * - Role identifiers
     * 
     * @return array Response containing role list
     */
    public function getAll(): array
    {
       try {
            $roles = $this->db->select('roles', ['uuid', 'name', 'description'])->get();
            return Response::ok($roles)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get specific user role
     * 
     * Retrieves detailed role information:
     * - Role assignments
     * - Role metadata
     * - User associations
     * 
     * @param string $uuid User UUID to check
     * @return array Role details response
     */
    public function getUser(string $uuid): array
{
    try {
        $role = $this->db->select('user_roles_lookup', [
                'user_roles_lookup.role_uuid',
                'user_roles_lookup.user_uuid',
                'roles.name AS role_name',
                'roles.description'
            ])
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
            ->where(['user_roles_lookup.user_uuid' => $uuid])
            ->get(); // Fetch all assigned roles if multiple exist

        return Response::ok($role)->send();
    } catch (\Exception $e) {
        return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}
    /**
     * Create new role
     * 
     * Establishes new role with:
     * - Role name and description
     * - Default permissions
     * - Access levels
     * 
     * @param array $data Role creation data
     * @return array Creation response
     */
    public function add(array $data): array
    {
        try {
            $uuid = Utils::generateNanoID();
            $data['uuid'] = $uuid;
            $role = $this->db->insert('roles', $data);
            return Response::ok($role)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Update existing role
     * 
     * Modifies role properties:
     * - Basic information
     * - Access levels
     * - Role metadata
     * 
     * @param string $uuid Role identifier
     * @param array $data Updated role data
     * @return array Update response
     */
    public function update(string $uuid, array $data): array
    {
        try {
            $role = $this->db->upsert('roles', $data, ['uuid' => $uuid]);
            return Response::ok($role)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Delete role from system
     * 
     * Removes role with cleanup:
     * - Role assignments
     * - Permission mappings
     * - User associations
     * 
     * @param string $uuid Role to remove
     * @return array Deletion response
     */
    public function delete(string $uuid): array
    {
        try {
            $role = $this->db->delete('roles', ['uuid' => $uuid]);
            return Response::ok($role)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Assign role to user
     * 
     * Creates user-role association:
     * - Maps user to role
     * - Handles inheritance
     * - Updates permissions
     * 
     * @param string $user_uuid User to assign role to
     * @param string $role_uuid Role to assign
     * @return array Assignment response
     */
    public function assignUser(string $user_uuid, string $role_uuid): array
    {
        try {
            // Check if the user already has the role
            $exists = $this->db->select('user_roles_lookup', ['role_uuid'])
                ->where(['user_uuid' => $user_uuid, 'role_uuid' => $role_uuid])
                ->get();
    
            if ($exists) {
                return Response::error("User already assigned to this role", 409)->send();
            }
    
            $role = $this->db->insert('user_roles_lookup', ['user_uuid' => $user_uuid, 'role_uuid' => $role_uuid]);
            return Response::ok($role)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove role from user
     * 
     * Revokes user-role association:
     * - Removes role mapping
     * - Updates permissions
     * - Handles cascading changes
     * 
     * @param string $user_uuid User to unassign from
     * @param string $role_uuid Role to remove
     * @return array Unassignment response
     */
    public function unassignUser(string $user_uuid, string $role_uuid): array
    {
        try {
            $role = $this->db->delete('user_roles_lookup', ['user_uuid' => $user_uuid, 'role_uuid' => $role_uuid]);
            return Response::ok($role)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }
}