<?php

namespace Glueful\Models;

use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;

/**
 * {{MODEL_NAME}} Model
 *
 * {{MODEL_DESCRIPTION}}
 *
 * @package Glueful\Models
 */
class {{MODEL_NAME}}
{
    /** @var QueryBuilder Database query builder */
    private QueryBuilder $db;

    /** @var string The table associated with the model */
    protected string $table = '{{TABLE_NAME}}';

    /** @var string The primary key for the model */
    protected string $primaryKey = 'id';

    /** @var array The attributes that are mass assignable */
    protected array $fillable = [
        // Add fillable attributes here
        // 'name',
        // 'email',
        // 'status',
    ];

    /** @var array The attributes that should be hidden */
    protected array $hidden = [
        // Add hidden attributes here
        // 'password',
        // 'remember_token',
    ];

    /** @var array The attributes that should be cast */
    protected array $casts = [
        // Add attribute casts here
        // 'email_verified_at' => 'datetime',
        // 'created_at' => 'datetime',
        // 'updated_at' => 'datetime',
    ];

    /**
     * Initialize model
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get all records
     *
     * @param array $columns
     * @return array
     */
    public function all(array $columns = ['*']): array
    {
        return $this->db->select($this->table, $columns)->get();
    }

    /**
     * Find a record by ID
     *
     * @param mixed $id
     * @param array $columns
     * @return array|null
     */
    public function find($id, array $columns = ['*']): ?array
    {
        $result = $this->db->select($this->table, $columns)
            ->where([$this->primaryKey => $id])
            ->get();

        return !empty($result) ? $result[0] : null;
    }

    /**
     * Find a record by specific conditions
     *
     * @param array $conditions
     * @param array $columns
     * @return array|null
     */
    public function findWhere(array $conditions, array $columns = ['*']): ?array
    {
        $result = $this->db->select($this->table, $columns)
            ->where($conditions)
            ->get();

        return !empty($result) ? $result[0] : null;
    }

    /**
     * Get records with conditions
     *
     * @param array $conditions
     * @param array $columns
     * @return array
     */
    public function where(array $conditions, array $columns = ['*']): array
    {
        return $this->db->select($this->table, $columns)
            ->where($conditions)
            ->get();
    }

    /**
     * Create a new record
     *
     * @param array $data
     * @return bool
     */
    public function create(array $data): bool
    {
        $data = $this->filterFillable($data);
        $data = $this->addTimestamps($data);
        
        return $this->db->insert($this->table, $data);
    }

    /**
     * Update a record by ID
     *
     * @param mixed $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data): bool
    {
        $data = $this->filterFillable($data);
        $data = $this->addTimestamps($data, true);
        
        return $this->db->update($this->table, $data, [$this->primaryKey => $id]);
    }

    /**
     * Delete a record by ID
     *
     * @param mixed $id
     * @return bool
     */
    public function delete($id): bool
    {
        return $this->db->delete($this->table, [$this->primaryKey => $id]);
    }

    /**
     * Count records
     *
     * @param array $conditions
     * @return int
     */
    public function count(array $conditions = []): int
    {
        $query = $this->db->select($this->table, ['COUNT(*) as count']);
        
        if (!empty($conditions)) {
            $query->where($conditions);
        }
        
        $result = $query->get();
        return (int)($result[0]['count'] ?? 0);
    }

    /**
     * Check if record exists
     *
     * @param array $conditions
     * @return bool
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    /**
     * Filter data to only fillable attributes
     *
     * @param array $data
     * @return array
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Add timestamps to data
     *
     * @param array $data
     * @param bool $isUpdate
     * @return array
     */
    protected function addTimestamps(array $data, bool $isUpdate = false): array
    {
        $now = date('Y-m-d H:i:s');
        
        if (!$isUpdate) {
            $data['created_at'] = $now;
        }
        
        $data['updated_at'] = $now;
        
        return $data;
    }

    /**
     * Remove hidden attributes from data
     *
     * @param array $data
     * @return array
     */
    public function hideAttributes(array $data): array
    {
        if (empty($this->hidden)) {
            return $data;
        }

        return array_diff_key($data, array_flip($this->hidden));
    }

    /**
     * Get the table name
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the primary key
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }
}