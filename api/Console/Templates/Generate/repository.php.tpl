<?php

declare(strict_types=1);

namespace Glueful\Repository;

/**
 * {{REPOSITORY_NAME}} Repository
 *
 * {{REPOSITORY_DESCRIPTION}}
 *
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality.
 *
 * @package Glueful\Repository
 */
class {{REPOSITORY_NAME}} extends BaseRepository
{
    /**
     * Initialize repository
     *
     * Sets up table configuration and calls parent constructor
     * for database connection setup.
     */
    public function __construct()
    {
        // Configure table settings before calling parent constructor
        $this->table = '{{TABLE_NAME}}';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['*'];
        $this->containsSensitiveData = false; // Set to true if handling sensitive data
        $this->sensitiveFields = []; // Add sensitive field names if needed

        // Call parent constructor to set up database connection
        parent::__construct();
    }

    /**
     * Find {{RESOURCE_NAME}} by specific criteria
     *
     * Custom finder method for {{RESOURCE_NAME}} specific searches.
     * Add your custom search logic here.
     *
     * @param array $criteria Search criteria
     * @param array|null $fields Fields to retrieve
     * @return array|null
     */
    public function findBy{{RESOURCE_CLASS}}Criteria(array $criteria, ?array $fields = null): ?array
    {
        // TODO: Implement custom search logic
        // Example:
        // if (isset($criteria['name'])) {
        //     return $this->findBy('name', $criteria['name'], $fields);
        // }
        
        return $this->findBy($this->primaryKey, $criteria[$this->primaryKey] ?? null, $fields);
    }

    /**
     * Get active {{RESOURCE_NAME}}s
     *
     * Retrieves all {{RESOURCE_NAME}}s with active status.
     * Customize this method based on your status field naming.
     *
     * @param array|null $fields Fields to retrieve
     * @return array
     */
    public function getActive{{RESOURCE_CLASS}}s(?array $fields = null): array
    {
        // TODO: Customize based on your status field
        // Assuming you have a 'status' field with 'active' value
        return $this->findAllBy('status', 'active', $fields);
    }

    /**
     * Search {{RESOURCE_NAME}}s by name or other searchable fields
     *
     * @param string $searchTerm Search term
     * @param array|null $fields Fields to retrieve
     * @return array
     */
    public function search{{RESOURCE_CLASS}}s(string $searchTerm, ?array $fields = null): array
    {
        // TODO: Implement search logic based on your table structure
        // This is a basic example - customize based on your searchable fields
        
        $conditions = [
            'name LIKE' => "%{$searchTerm}%"
            // Add more searchable fields as needed
            // 'description LIKE' => "%{$searchTerm}%",
            // 'email LIKE' => "%{$searchTerm}%",
        ];
        
        return $this->db->select($this->table, $fields ?? $this->defaultFields)
            ->where($conditions)
            ->get();
    }

    /**
     * Get {{RESOURCE_NAME}}s with relationships
     *
     * Custom method to retrieve {{RESOURCE_NAME}}s with their related data.
     * Implement joins or separate queries as needed.
     *
     * @param array|null $fields Fields to retrieve
     * @return array
     */
    public function get{{RESOURCE_CLASS}}sWithRelations(?array $fields = null): array
    {
        // TODO: Implement relationship loading
        // Example: Join with related tables or use separate queries
        // This is a placeholder - implement based on your relationships
        
        return $this->getAll($fields);
    }

    /**
     * Find {{RESOURCE_NAME}} by custom business logic
     *
     * Implement custom finder methods specific to your {{RESOURCE_NAME}} entity.
     * This method should contain business-specific search logic.
     *
     * @param string $identifier Custom identifier (email, slug, etc.)
     * @param array|null $fields Fields to retrieve
     * @return array|null
     */
    public function findBy{{RESOURCE_CLASS}}Identifier(string $identifier, ?array $fields = null): ?array
    {
        // TODO: Implement custom identifier search
        // Examples:
        // - Find by email
        // - Find by slug
        // - Find by username
        // - Find by custom composite key
        
        // Placeholder implementation - customize based on your needs
        $possibleFields = ['name', 'slug', 'email', 'username'];
        
        foreach ($possibleFields as $field) {
            $result = $this->findBy($field, $identifier, $fields);
            if ($result) {
                return $result;
            }
        }
        
        return null;
    }

    /**
     * Get statistics for {{RESOURCE_NAME}}s
     *
     * Custom method to retrieve statistical data about {{RESOURCE_NAME}}s.
     * Useful for dashboards and reporting.
     *
     * @return array Statistical data
     */
    public function get{{RESOURCE_CLASS}}Statistics(): array
    {
        // TODO: Implement statistics gathering
        // Examples:
        // - Count by status
        // - Count by date ranges
        // - Average values
        // - Min/max values
        
        $total = $this->count();
        $active = $this->count(['status' => 'active']);
        
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            // Add more statistics as needed
        ];
    }
}