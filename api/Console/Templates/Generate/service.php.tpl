<?php

namespace Glueful\Services;

use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;

/**
 * {{SERVICE_NAME}} Service
 *
 * {{SERVICE_DESCRIPTION}}
 *
 * @package Glueful\Services
 */
class {{SERVICE_NAME}}
{
    /** @var QueryBuilder Database query builder */
    private QueryBuilder $db;

    /**
     * Initialize service
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Main service method
     *
     * @param array $data
     * @return array
     */
    public function process(array $data): array
    {
        // TODO: Implement your main service logic
        return [
            'success' => true,
            'message' => '{{SERVICE_NAME}} processed successfully',
            'data' => $data
        ];
    }

    /**
     * Create a new {{RESOURCE_NAME}}
     *
     * @param array $data
     * @return array
     */
    public function create{{RESOURCE_CLASS}}(array $data): array
    {
        try {
            // Validate input data
            $validatedData = $this->validate{{RESOURCE_CLASS}}Data($data);
            
            // Process business logic
            $processedData = $this->process{{RESOURCE_CLASS}}Data($validatedData);
            
            // Store in database
            $result = $this->store{{RESOURCE_CLASS}}($processedData);
            
            return [
                'success' => true,
                'message' => '{{RESOURCE_CLASS}} created successfully',
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create {{RESOURCE_NAME}}: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Update an existing {{RESOURCE_NAME}}
     *
     * @param int|string $id
     * @param array $data
     * @return array
     */
    public function update{{RESOURCE_CLASS}}($id, array $data): array
    {
        try {
            // Check if {{RESOURCE_NAME}} exists
            if (!$this->{{RESOURCE_NAME}}Exists($id)) {
                return [
                    'success' => false,
                    'message' => '{{RESOURCE_CLASS}} not found',
                    'data' => null
                ];
            }

            // Validate input data
            $validatedData = $this->validate{{RESOURCE_CLASS}}Data($data, true);
            
            // Process business logic
            $processedData = $this->process{{RESOURCE_CLASS}}Data($validatedData, $id);
            
            // Update in database
            $result = $this->update{{RESOURCE_CLASS}}InDatabase($id, $processedData);
            
            return [
                'success' => true,
                'message' => '{{RESOURCE_CLASS}} updated successfully',
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update {{RESOURCE_NAME}}: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Delete a {{RESOURCE_NAME}}
     *
     * @param int|string $id
     * @return array
     */
    public function delete{{RESOURCE_CLASS}}($id): array
    {
        try {
            // Check if {{RESOURCE_NAME}} exists
            if (!$this->{{RESOURCE_NAME}}Exists($id)) {
                return [
                    'success' => false,
                    'message' => '{{RESOURCE_CLASS}} not found',
                    'data' => null
                ];
            }

            // Perform any pre-deletion checks
            $canDelete = $this->canDelete{{RESOURCE_CLASS}}($id);
            if (!$canDelete['allowed']) {
                return [
                    'success' => false,
                    'message' => $canDelete['reason'],
                    'data' => null
                ];
            }

            // Delete from database
            $this->delete{{RESOURCE_CLASS}}FromDatabase($id);
            
            return [
                'success' => true,
                'message' => '{{RESOURCE_CLASS}} deleted successfully',
                'data' => ['id' => $id]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete {{RESOURCE_NAME}}: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Get {{RESOURCE_NAME}} by ID
     *
     * @param int|string $id
     * @return array|null
     */
    public function get{{RESOURCE_CLASS}}($id): ?array
    {
        // TODO: Implement get logic
        $result = $this->db->select('{{TABLE_NAME}}', ['*'])
            ->where(['id' => $id])
            ->get();

        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get all {{RESOURCE_NAME}}s with optional filtering
     *
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAll{{RESOURCE_CLASS}}s(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        // TODO: Implement list logic with filtering
        $query = $this->db->select('{{TABLE_NAME}}', ['*']);
        
        // Apply filters
        if (!empty($filters)) {
            $query->where($filters);
        }
        
        // Apply pagination
        $query->limit($limit)->offset($offset);
        
        return $query->get();
    }

    /**
     * Validate {{RESOURCE_NAME}} data
     *
     * @param array $data
     * @param bool $isUpdate
     * @return array
     * @throws \InvalidArgumentException
     */
    private function validate{{RESOURCE_CLASS}}Data(array $data, bool $isUpdate = false): array
    {
        // TODO: Implement validation logic
        $rules = [
            // Add validation rules here
            // 'name' => 'required|string|max:255',
            // 'email' => 'required|email|unique:users',
        ];

        if ($isUpdate) {
            // Modify rules for updates (e.g., make some fields optional)
            // $rules['email'] = 'sometimes|email|unique:users';
        }

        // Implement validation logic or use a validator
        return $data; // Placeholder
    }

    /**
     * Process {{RESOURCE_NAME}} data (business logic)
     *
     * @param array $data
     * @param int|string|null $id
     * @return array
     */
    private function process{{RESOURCE_CLASS}}Data(array $data, $id = null): array
    {
        // TODO: Implement business logic processing
        // Examples:
        // - Calculate derived fields
        // - Apply business rules
        // - Transform data
        // - Generate additional fields
        
        // Add timestamps
        if (!$id) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        return $data;
    }

    /**
     * Store {{RESOURCE_NAME}} in database
     *
     * @param array $data
     * @return array
     */
    private function store{{RESOURCE_CLASS}}(array $data): array
    {
        // TODO: Implement storage logic
        $this->db->insert('{{TABLE_NAME}}', $data);
        
        // Get the created record (assuming auto-increment ID)
        $id = $this->db->getPDO()->lastInsertId();
        return $this->get{{RESOURCE_CLASS}}($id);
    }

    /**
     * Update {{RESOURCE_NAME}} in database
     *
     * @param int|string $id
     * @param array $data
     * @return array
     */
    private function update{{RESOURCE_CLASS}}InDatabase($id, array $data): array
    {
        // TODO: Implement update logic
        $this->db->update('{{TABLE_NAME}}', $data, ['id' => $id]);
        
        return $this->get{{RESOURCE_CLASS}}($id);
    }

    /**
     * Delete {{RESOURCE_NAME}} from database
     *
     * @param int|string $id
     * @return bool
     */
    private function delete{{RESOURCE_CLASS}}FromDatabase($id): bool
    {
        // TODO: Implement deletion logic
        return $this->db->delete('{{TABLE_NAME}}', ['id' => $id]);
    }

    /**
     * Check if {{RESOURCE_NAME}} exists
     *
     * @param int|string $id
     * @return bool
     */
    private function {{RESOURCE_NAME}}Exists($id): bool
    {
        $result = $this->db->select('{{TABLE_NAME}}', ['COUNT(*) as count'])
            ->where(['id' => $id])
            ->get();
            
        return (int)($result[0]['count'] ?? 0) > 0;
    }

    /**
     * Check if {{RESOURCE_NAME}} can be deleted
     *
     * @param int|string $id
     * @return array
     */
    private function canDelete{{RESOURCE_CLASS}}($id): array
    {
        // TODO: Implement deletion rules
        // Examples:
        // - Check for dependent records
        // - Check user permissions
        // - Check business rules
        
        return [
            'allowed' => true,
            'reason' => null
        ];
    }

    /**
     * Search {{RESOURCE_NAME}}s
     *
     * @param string $query
     * @param array $fields
     * @return array
     */
    public function search{{RESOURCE_CLASS}}s(string $query, array $fields = ['name']): array
    {
        // TODO: Implement search logic
        // This is a basic example - you might want to use full-text search
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = "{$field} LIKE '%{$query}%'";
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        // Note: This is a simplified example. Use proper query building for production
        return $this->db->select('{{TABLE_NAME}}', ['*'])
            ->whereRaw($whereClause)
            ->get();
    }
}