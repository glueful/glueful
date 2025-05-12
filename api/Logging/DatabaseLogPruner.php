<?php

declare(strict_types=1);

namespace Glueful\Logging;

use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;

/**
 * Log Pruner for DatabaseLogHandler
 * Handles cleanup of old database logs based on configurable retention settings
 */
class DatabaseLogPruner
{
    private QueryBuilder $db;
    private int $maxAgeInDays;
    private int $maxRecords;

    public function __construct(
        int $maxAgeInDays = 90,  // Keep logs for 90 days
        int $maxRecords = 1000000 // Keep 1 million most recent records
    ) {
        $this->maxAgeInDays = $maxAgeInDays;
        $this->maxRecords = $maxRecords;

        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Prune old log entries based on age and quantity limits
     */
    public function prune(): array
    {
        $deletedByAge = $this->pruneByAge();
        $deletedByQuantity = $this->pruneByQuantity();

        return [
            'deleted_by_age' => $deletedByAge,
            'deleted_by_quantity' => $deletedByQuantity
        ];
    }

    /**
     * Delete logs older than maxAgeInDays
     */
    private function pruneByAge(): mixed
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->maxAgeInDays} days"));

        return $this->db->delete(
            'app_logs',
            ['created_at <' => $cutoffDate],
            false
        );
    }

    /**
     * Keep only the most recent maxRecords
     */
    private function pruneByQuantity(): mixed
    {
        // Get total count using the correct query pattern
        $totalRecords = $this->db->select('app_logs', [''])
        ->count('app_logs', ['id']);

        if ($totalRecords <= $this->maxRecords) {
            return 0;
        }

        // Find ID threshold for deletion
        $threshold = $this->db->select('app_logs', ['id'])
            ->orderBy(['id' => 'DESC'])
            ->limit(1)
            ->offset($this->maxRecords)
            ->get()[0] ?? null;

        if (!$threshold) {
            return 0;
        }


            return $this->db->delete(
                'app_logs',
                ['id <' => $threshold['id']],
                false
            );
    }
}
