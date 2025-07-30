<?php

namespace Glueful\Services\Archive;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Security\RandomStringGenerator;
use Glueful\Services\Archive\DTOs\ArchiveResult;
use Glueful\Services\Archive\DTOs\ArchiveSearchQuery;
use Glueful\Services\Archive\DTOs\ArchiveSearchResult;
use Glueful\Services\Archive\DTOs\ArchiveRestoreOptions;
use Glueful\Services\Archive\DTOs\RestoreResult;
use Glueful\Services\Archive\DTOs\TableArchiveStats;
use Glueful\Services\Archive\DTOs\ArchiveSummary;
use Glueful\Services\Archive\DTOs\ExportResult;
use Glueful\Services\Archive\DTOs\ArchiveFile;
use Glueful\Helpers\Utils;
use Glueful\Exceptions\DatabaseException;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Constants\ErrorCodes;
use Glueful\Services\FileManager;

/**
 * Archive Service Implementation
 *
 * Provides data archiving capabilities including compression,
 * encryption, search indexing, and restoration.
 *
 * @package Glueful\Services\Archive
 */
class ArchiveService implements ArchiveServiceInterface
{
    private string $archiveBasePath;
    private ?string $encryptionKey;
    private array $config;
    private FileManager $fileManager;

    private Connection $db;

    public function __construct(
        ?Connection $connection = null,
        private ?SchemaBuilderInterface $schemaManager = null,
        private ?RandomStringGenerator $randomGenerator = null,
        array $config = [],
        ?FileManager $fileManager = null
    ) {
        $this->db = $connection ?? new Connection();
        $this->schemaManager = $this->schemaManager ?? $this->db->getSchemaBuilder();
        $this->randomGenerator = $this->randomGenerator ?? new RandomStringGenerator();
        $this->fileManager = $fileManager ?? container()->get(FileManager::class);
        $this->config = array_merge([
            'storage_path' => config('archive.storage.path'),
            'encryption_key' => $_ENV['ARCHIVE_ENCRYPTION_KEY'] ?? null,
            'compression' => 'gzip',
            'chunk_size' => 10000,
            'verify_checksums' => true
        ], $config);

        $this->archiveBasePath = $this->config['storage_path'];
        $this->encryptionKey = $this->config['encryption_key'];

        // Ensure archive directory exists using FileManager
        if (!$this->fileManager->exists($this->archiveBasePath)) {
            $this->fileManager->createDirectory($this->archiveBasePath);
        }
    }

    public function archiveTable(string $table, \DateTime $cutoffDate): ArchiveResult
    {
        $archiveUuid = Utils::generateNanoID();

        try {
            // 1. Validate table exists
            if (!$this->validateTable($table)) {
                return ArchiveResult::failure("Table {$table} does not exist");
            }

            // 2. Export data
            $exportResult = $this->exportTableData($table, $cutoffDate);
            if ($exportResult->recordCount === 0) {
                return ArchiveResult::failure("No records found to archive");
            }

            // 3. Compress and encrypt
            $archiveFile = $this->compressAndEncrypt($exportResult);

            // 4. Register archive
            $this->registerArchive([
                'uuid' => $archiveUuid,
                'table_name' => $table,
                'archive_date' => date('Y-m-d'),
                'period_start' => $this->getEarliestRecord($table, $cutoffDate),
                'period_end' => $cutoffDate->format('Y-m-d H:i:s'),
                'record_count' => $exportResult->recordCount,
                'file_path' => $archiveFile->path,
                'file_size' => $archiveFile->size,
                'checksum_sha256' => $archiveFile->checksum,
                'metadata' => json_encode($exportResult->metadata)
            ]);

            // 5. Create search index
            $this->createSearchIndex($archiveUuid, $exportResult->data);

            // 6. Verify archive
            if (!$this->verifyArchive($archiveUuid)) {
                throw BusinessLogicException::operationNotAllowed(
                    'archive_verification',
                    'Archive verification failed'
                );
            }

            // 7. Delete original data
            $deletedCount = $this->deleteArchivedData($table, $cutoffDate);

            // 8. Update archive status
            $this->updateArchiveStatus($archiveUuid, 'completed');

            // 9. Update table stats
            $this->updateTableStats($table);

            return ArchiveResult::success(
                $archiveUuid,
                $exportResult->recordCount,
                $archiveFile->size,
                $archiveFile->path,
                ['deleted_count' => $deletedCount]
            );
        } catch (\Exception $e) {
            $this->cleanupFailedArchive($archiveUuid);
            error_log("Archive failed for table {$table}: " . $e->getMessage());
            return ArchiveResult::failure($e->getMessage());
        }
    }

    private function exportTableData(string $table, \DateTime $cutoffDate): ExportResult
    {
        $data = [];
        $recordCount = 0;
        $chunkSize = $this->config['chunk_size'];
        $offset = 0;

        // Get table schema
        $schema = $this->schemaManager->getTableColumns($table);

        do {
            $chunk = $this->db->table($table)
                ->select(['*'])
                ->where('created_at', '<', $cutoffDate->format('Y-m-d H:i:s'))
                ->orderBy('created_at', 'ASC')
                ->limit($chunkSize)
                ->offset($offset)
                ->get();

            if (!empty($chunk)) {
                $data = array_merge($data, $chunk);
                $recordCount += count($chunk);
                $offset += $chunkSize;
            }
        } while (!empty($chunk));

        $metadata = [
            'table_name' => $table,
            'schema' => $schema,
            'export_timestamp' => time(),
            'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
            'total_records' => $recordCount,
            'first_record_date' => $data[0]['created_at'] ?? null,
            'last_record_date' => end($data)['created_at'] ?? null,
            'compression' => $this->config['compression'],
            'encryption_enabled' => !empty($this->encryptionKey)
        ];

        return new ExportResult($data, $recordCount, $metadata);
    }

    private function compressAndEncrypt(ExportResult $exportResult): ArchiveFile
    {
        $filename = sprintf(
            '%s_%s_%s.json.gz.enc',
            $exportResult->metadata['table_name'],
            date('Y-m'),
            uniqid()
        );

        $filepath = $this->archiveBasePath . '/' . $filename;

        // 1. JSON encode
        $jsonData = json_encode([
            'metadata' => $exportResult->metadata,
            'data' => $exportResult->data
        ], JSON_UNESCAPED_UNICODE);

        // 2. Compress
        $compressedData = gzencode($jsonData, 9);

        // 3. Encrypt if enabled
        if ($this->encryptionKey) {
            $iv = random_bytes(16);
            $encryptedData = openssl_encrypt(
                $compressedData,
                'AES-256-GCM',
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            $finalData = $iv . $tag . $encryptedData;
        } else {
            $finalData = $compressedData;
        }

        // 4. Write to file
        file_put_contents($filepath, $finalData);

        return new ArchiveFile(
            $filepath,
            filesize($filepath),
            hash('sha256', $finalData)
        );
    }

    private function createSearchIndex(string $archiveUuid, array $data): void
    {
        $indexes = [];

        foreach ($data as $record) {
            // Create indexes for common searchable fields
            $this->addToSearchIndex($indexes, 'user', $record['user_uuid'] ?? null, $record);
            $this->addToSearchIndex($indexes, 'endpoint', $record['endpoint'] ?? null, $record);
            $this->addToSearchIndex($indexes, 'action', $record['action'] ?? null, $record);
            $this->addToSearchIndex($indexes, 'ip_address', $record['ip_address'] ?? null, $record);
            $this->addToSearchIndex($indexes, 'status', $record['status'] ?? null, $record);
        }

        // Collect all index entries for bulk insert to avoid N+1 queries
        $indexEntries = [];
        foreach ($indexes as $entityType => $entities) {
            foreach ($entities as $entityValue => $indexData) {
                if ($entityValue && $indexData['count'] > 0) {
                    $indexEntries[] = [
                        'archive_uuid' => $archiveUuid,
                        'entity_type' => $entityType,
                        'entity_value' => $entityValue,
                        'record_count' => $indexData['count'],
                        'first_occurrence' => $indexData['first'],
                        'last_occurrence' => $indexData['last']
                    ];
                }
            }
        }

        // Perform bulk insert if we have entries
        if (!empty($indexEntries)) {
            $this->insertBatchSearchIndexes($indexEntries);
        }
    }

    private function addToSearchIndex(array &$indexes, string $type, ?string $value, array $record): void
    {
        if (!$value) {
            return;
        }

        if (!isset($indexes[$type])) {
            $indexes[$type] = [];
        }

        if (!isset($indexes[$type][$value])) {
            $indexes[$type][$value] = [
                'count' => 0,
                'first' => null,
                'last' => null
            ];
        }

        $indexes[$type][$value]['count']++;

        $timestamp = $record['created_at'] ?? date('Y-m-d H:i:s');
        if (!$indexes[$type][$value]['first']) {
            $indexes[$type][$value]['first'] = $timestamp;
        }
        $indexes[$type][$value]['last'] = $timestamp;
    }

    public function trackTableGrowth(string $table): void
    {
        try {
            // Get current table stats from information_schema
            $tableInfo = $this->db->table('information_schema.tables')
                ->select(['*'])
                ->where('table_schema', $this->getDatabaseName())
                ->where('table_name', $table)
                ->first();

            if ($tableInfo) {
                $rowCount = $this->db->table($table)->count();

                $sizeBytes = ($tableInfo['data_length'] ?? 0) + ($tableInfo['index_length'] ?? 0);

                // Update or insert table stats
                $existing = $this->db->table('archive_table_stats')
                    ->select(['*'])
                    ->where('table_name', $table)
                    ->first();

                if ($existing) {
                    $this->db->table('archive_table_stats')
                        ->where('table_name', $table)
                        ->update([
                            'current_size_bytes' => $sizeBytes,
                            'current_row_count' => $rowCount,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } else {
                    $this->db->table('archive_table_stats')->insert([
                        'table_name' => $table,
                        'current_size_bytes' => $sizeBytes,
                        'current_row_count' => $rowCount
                    ]);
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to track table growth for {$table}: " . $e->getMessage());
        }
    }

    public function getTableStats(string $table): ?TableArchiveStats
    {
        $stats = $this->db->table('archive_table_stats')
            ->select(['*'])
            ->where('table_name', $table)
            ->first();

        if (!$stats) {
            return null;
        }

        $needsArchive = $this->determineIfNeedsArchive($stats);

        return new TableArchiveStats(
            tableName: $table,
            currentRowCount: $stats['current_row_count'],
            currentSizeBytes: $stats['current_size_bytes'],
            lastArchiveDate: $stats['last_archive_date'] ? new \DateTime($stats['last_archive_date']) : null,
            nextArchiveDate: $stats['next_archive_date'] ? new \DateTime($stats['next_archive_date']) : null,
            needsArchive: $needsArchive,
            thresholdRows: $stats['archive_threshold_rows'],
            thresholdDays: $stats['archive_threshold_days']
        );
    }

    public function searchArchives(ArchiveSearchQuery $query): ArchiveSearchResult
    {
        $startTime = microtime(true);
        $relevantArchives = $this->findRelevantArchives($query);
        $results = [];

        foreach ($relevantArchives as $archive) {
            try {
                $archiveData = $this->loadArchive($archive['uuid']);
                $matches = $this->searchArchiveData($archiveData, $query);
                $results = array_merge($results, $matches);
            } catch (\Exception $e) {
                error_log("Failed to search archive {$archive['uuid']}: " . $e->getMessage());
            }
        }

        $searchTime = microtime(true) - $startTime;

        return new ArchiveSearchResult(
            array_slice($results, $query->offset, $query->limit),
            count($results),
            array_column($relevantArchives, 'uuid'),
            $searchTime
        );
    }

    public function verifyArchive(string $archiveUuid): bool
    {
        try {
            $archive = $this->getArchiveRecord($archiveUuid);
            if (!$archive) {
                return false;
            }

            // Check file exists
            if (!file_exists($archive['file_path'])) {
                return false;
            }

            // Verify checksum if configured
            if ($this->config['verify_checksums']) {
                $currentChecksum = hash('sha256', file_get_contents($archive['file_path']));
                if ($currentChecksum !== $archive['checksum_sha256']) {
                    $this->updateArchiveStatus($archiveUuid, 'corrupted');
                    return false;
                }
            }

            $this->updateArchiveStatus($archiveUuid, 'verified');
            return true;
        } catch (\Exception $e) {
            error_log("Archive verification failed for {$archiveUuid}: " . $e->getMessage());
            return false;
        }
    }

    public function restoreFromArchive(string $archiveUuid, ?ArchiveRestoreOptions $options = null): RestoreResult
    {
        // Implementation for restore functionality
        // This is a placeholder - full implementation would be quite complex
        return RestoreResult::failure("Restore functionality not yet implemented");
    }

    public function deleteArchive(string $archiveUuid): bool
    {
        try {
            $archive = $this->getArchiveRecord($archiveUuid);
            if (!$archive) {
                return false;
            }

            // Delete physical file
            if (file_exists($archive['file_path'])) {
                unlink($archive['file_path']);
            }

            // Delete from database (cascade will handle search indexes)
            $result = $this->db->table('archive_registry')
                ->where('uuid', $archiveUuid)
                ->delete();

            return $result > 0;
        } catch (\Exception $e) {
            error_log("Failed to delete archive {$archiveUuid}: " . $e->getMessage());
            return false;
        }
    }

    public function getArchiveSummary(): ArchiveSummary
    {
        $totalArchives = $this->db->table('archive_registry')->count();

        $totals = $this->db->table('archive_registry')
            ->selectRaw('SUM(record_count) as total_records, SUM(file_size) as total_size')
            ->first();

        $tableBreakdown = $this->db->table('archive_registry')
            ->select(['table_name'])
            ->selectRaw('COUNT(*) as count, SUM(record_count) as records, SUM(file_size) as size')
            ->groupBy('table_name')
            ->get();

        $breakdown = [];
        foreach ($tableBreakdown as $row) {
            $breakdown[$row['table_name']] = [
                'count' => $row['count'],
                'records' => $row['records'],
                'size' => $row['size']
            ];
        }

        $dates = $this->db->table('archive_registry')
            ->selectRaw('MIN(created_at) as oldest, MAX(created_at) as newest')
            ->first();

        return new ArchiveSummary(
            totalArchives: $totalArchives,
            totalRecordsArchived: $totals['total_records'] ?? 0,
            totalSizeBytes: $totals['total_size'] ?? 0,
            tableBreakdown: $breakdown,
            oldestArchive: $dates['oldest'] ? new \DateTime($dates['oldest']) : null,
            newestArchive: $dates['newest'] ? new \DateTime($dates['newest']) : null
        );
    }

    public function getTablesNeedingArchival(): array
    {
        $tables = $this->db->table('archive_table_stats')
            ->select(['*'])
            ->where('auto_archive_enabled', true)
            ->get();

        $needingArchival = [];
        foreach ($tables as $table) {
            if ($this->determineIfNeedsArchive($table)) {
                $needingArchival[] = $table['table_name'];
            }
        }

        return $needingArchival;
    }

    public function getTableArchives(string $table): array
    {
        return $this->db->table('archive_registry')
            ->select(['*'])
            ->where('table_name', $table)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    // Helper methods

    private function validateTable(string $table): bool
    {
        try {
            $this->schemaManager->getTableColumns($table);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function getEarliestRecord(string $table, \DateTime $cutoffDate): string
    {
        $earliest = $this->db->table($table)
            ->selectRaw('MIN(created_at) as earliest')
            ->where('created_at', '<', $cutoffDate->format('Y-m-d H:i:s'))
            ->first();

        return $earliest['earliest'] ?? $cutoffDate->format('Y-m-d H:i:s');
    }

    private function deleteArchivedData(string $table, \DateTime $cutoffDate): int
    {
        return $this->db->table($table)
            ->where('created_at', '<', $cutoffDate->format('Y-m-d H:i:s'))
            ->delete();
    }

    private function registerArchive(array $data): void
    {
        $this->db->table('archive_registry')->insert($data);
    }

    private function updateArchiveStatus(string $uuid, string $status): void
    {
        $this->db->table('archive_registry')
            ->where('uuid', $uuid)
            ->update(['status' => $status]);
    }

    private function updateTableStats(string $table): void
    {
        $this->db->table('archive_table_stats')
            ->where('table_name', $table)
            ->update([
                'last_archive_date' => date('Y-m-d'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }

    private function cleanupFailedArchive(string $archiveUuid): void
    {
        try {
            $archive = $this->getArchiveRecord($archiveUuid);
            if ($archive && file_exists($archive['file_path'])) {
                unlink($archive['file_path']);
            }

            $this->db->table('archive_registry')
                ->where('uuid', $archiveUuid)
                ->delete();
        } catch (\Exception $e) {
            error_log("Failed to cleanup archive {$archiveUuid}: " . $e->getMessage());
        }
    }

    private function getArchiveRecord(string $archiveUuid): ?array
    {
        return $this->db->table('archive_registry')
            ->select(['*'])
            ->where('uuid', $archiveUuid)
            ->first();
    }

    private function determineIfNeedsArchive(array $stats): bool
    {
        $rowThreshold = $stats['current_row_count'] >= $stats['archive_threshold_rows'];

        $timeThreshold = false;
        if ($stats['last_archive_date']) {
            $daysSince = (new \DateTime())->diff(new \DateTime($stats['last_archive_date']))->days;
            $timeThreshold = $daysSince >= $stats['archive_threshold_days'];
        } else {
            $timeThreshold = true; // Never archived
        }

        return $rowThreshold || $timeThreshold;
    }

    private function getDatabaseName(): string
    {
        // This would need to be implemented based on your connection setup
        return $_ENV['DB_NAME'] ?? 'glueful';
    }

    private function findRelevantArchives(ArchiveSearchQuery $query): array
    {
        // Implementation for finding relevant archives based on search query
        // This is a simplified version - full implementation would be more complex
        $archiveQuery = $this->db->table('archive_registry as ar')->select(['ar.*']);

        if (!empty($query->tables)) {
            $archiveQuery->whereIn('table_name', $query->tables);
        }

        if ($query->startDate) {
            $archiveQuery->where('period_end', '>=', $query->startDate->format('Y-m-d H:i:s'));
        }

        if ($query->endDate) {
            $archiveQuery->where('period_start', '<=', $query->endDate->format('Y-m-d H:i:s'));
        }

        return $archiveQuery->get();
    }

    private function loadArchive(string $archiveUuid): array
    {
        try {
            // Get archive metadata from database
            $archive = $this->getArchiveRecord($archiveUuid);
            if (!$archive) {
                throw BusinessLogicException::operationNotAllowed(
                    'archive_restore',
                    "Archive {$archiveUuid} not found"
                );
            }

            // Check if file exists
            if (!file_exists($archive['file_path'])) {
                throw BusinessLogicException::operationNotAllowed(
                    'archive_restore',
                    "Archive file not found: {$archive['file_path']}"
                );
            }

            // Read the archive file
            $fileData = file_get_contents($archive['file_path']);
            if ($fileData === false) {
                throw DatabaseException::queryFailed(
                    'READ',
                    "Failed to read archive file: {$archive['file_path']}"
                );
            }

            // Verify checksum if configured
            if ($this->config['verify_checksums']) {
                $currentChecksum = hash('sha256', $fileData);
                if ($currentChecksum !== $archive['checksum_sha256']) {
                    throw BusinessLogicException::operationNotAllowed(
                        'archive_restore',
                        'Archive file corrupted - checksum mismatch'
                    );
                }
            }

            // Decrypt if encryption was used
            $decompressedData = $fileData;
            if ($this->encryptionKey && !empty($archive['metadata'])) {
                $metadata = json_decode($archive['metadata'], true);
                if ($metadata['encryption_enabled'] ?? false) {
                    $decompressedData = $this->decryptArchiveData($fileData);
                }
            }

            // Decompress the data
            $jsonData = $this->decompressArchiveData($decompressedData, $archive);

            // Parse JSON
            $archiveContent = json_decode($jsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to parse archive JSON: " . json_last_error_msg());
            }

            // Return the data array from the archive
            return $archiveContent['data'] ?? [];
        } catch (\Exception $e) {
            error_log("Failed to load archive {$archiveUuid}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Decrypt archive data using AES-256-GCM
     *
     * @param string $encryptedData The encrypted data (IV + tag + encrypted content)
     * @return string Decrypted data
     * @throws \Exception If decryption fails
     */
    private function decryptArchiveData(string $encryptedData): string
    {
        if (!$this->encryptionKey) {
            throw new \Exception("No encryption key available for decryption");
        }

        // Extract IV (first 16 bytes), tag (next 16 bytes), and encrypted data (rest)
        if (strlen($encryptedData) < 32) {
            throw new \Exception("Invalid encrypted data format - too short");
        }

        $iv = substr($encryptedData, 0, 16);
        $tag = substr($encryptedData, 16, 16);
        $encrypted = substr($encryptedData, 32);

        // Decrypt using AES-256-GCM
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-GCM',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \Exception("Failed to decrypt archive data - invalid key or corrupted data");
        }

        return $decrypted;
    }

    /**
     * Decompress archive data
     *
     * @param string $compressedData The compressed data
     * @param array $archive Archive metadata for compression type detection
     * @return string Decompressed JSON data
     * @throws \Exception If decompression fails
     */
    private function decompressArchiveData(string $compressedData, array $archive): string
    {
        // Get compression type from metadata or config
        $metadata = json_decode($archive['metadata'] ?? '{}', true);
        $compressionType = $metadata['compression'] ?? $this->config['compression'];

        switch ($compressionType) {
            case 'gzip':
                $decompressed = gzdecode($compressedData);
                if ($decompressed === false) {
                    throw new \Exception("Failed to decompress gzip data");
                }
                return $decompressed;

            case 'bzip2':
                $decompressed = bzdecompress($compressedData);
                if ($decompressed === false || is_int($decompressed)) {
                    throw new \Exception("Failed to decompress bzip2 data");
                }
                return $decompressed;

            case 'none':
                return $compressedData;

            default:
                throw new \Exception("Unsupported compression type: {$compressionType}");
        }
    }

    private function searchArchiveData(array $archiveData, ArchiveSearchQuery $query): array
    {
        $results = [];
        $processedCount = 0;

        foreach ($archiveData as $record) {
            // Skip if we've reached the limit + offset
            if (count($results) >= $query->limit) {
                break;
            }

            // Apply filters based on search query
            if (!$this->matchesSearchCriteria($record, $query)) {
                continue;
            }

            // Apply offset - skip records until we reach the offset
            if ($processedCount < $query->offset) {
                $processedCount++;
                continue;
            }

            $results[] = $record;
            $processedCount++;
        }

        return $results;
    }

    /**
     * Check if a record matches the search criteria
     *
     * @param array $record Individual record from archive
     * @param ArchiveSearchQuery $query Search criteria
     * @return bool True if record matches all criteria
     */
    private function matchesSearchCriteria(array $record, ArchiveSearchQuery $query): bool
    {
        // User UUID filter
        if ($query->userUuid !== null) {
            $recordUserUuid = $record['user_uuid'] ?? $record['user_id'] ?? null;
            if ($recordUserUuid !== $query->userUuid) {
                return false;
            }
        }

        // Endpoint filter
        if ($query->endpoint !== null) {
            $recordEndpoint = $record['endpoint'] ?? $record['url'] ?? $record['path'] ?? null;
            if ($recordEndpoint !== $query->endpoint) {
                return false;
            }
        }

        // Action filter
        if ($query->action !== null) {
            $recordAction = $record['action'] ?? $record['method'] ?? $record['event_type'] ?? null;
            if ($recordAction !== $query->action) {
                return false;
            }
        }

        // IP Address filter
        if ($query->ipAddress !== null) {
            $recordIp = $record['ip_address'] ?? $record['client_ip'] ?? $record['remote_addr'] ?? null;
            if ($recordIp !== $query->ipAddress) {
                return false;
            }
        }

        // Date range filters
        if ($query->startDate !== null || $query->endDate !== null) {
            $recordDate = $this->extractRecordDate($record);
            if ($recordDate === null) {
                return false; // Skip records without valid dates
            }

            if ($query->startDate !== null && $recordDate < $query->startDate) {
                return false;
            }

            if ($query->endDate !== null && $recordDate > $query->endDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract date from record for date range filtering
     *
     * @param array $record Individual record from archive
     * @return \DateTime|null Parsed date or null if not found/invalid
     */
    private function extractRecordDate(array $record): ?\DateTime
    {
        // Try common date field names
        $dateFields = ['created_at', 'updated_at', 'timestamp', 'date', 'occurred_at', 'logged_at'];

        foreach ($dateFields as $field) {
            if (isset($record[$field])) {
                try {
                    return new \DateTime($record[$field]);
                } catch (\Exception) {
                    // Try next field if date parsing fails
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Insert multiple search index entries in a single batch operation
     *
     * @param array $indexEntries Array of index entry data
     * @return bool Success status
     */
    private function insertBatchSearchIndexes(array $indexEntries): bool
    {
        if (empty($indexEntries)) {
            return true;
        }

        try {
            // Use insertBatch for efficient bulk insert
            $result = $this->db->table('archive_search_index')->insertBatch($indexEntries);
            return $result > 0;
        } catch (\Exception $e) {
            error_log("Failed to insert batch search indexes: " . $e->getMessage());
            return false;
        }
    }
}
