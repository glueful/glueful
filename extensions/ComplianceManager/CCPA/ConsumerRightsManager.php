<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager\CCPA;

use Glueful\ComplianceManager\DataClassifier;

/**
 * Consumer Rights Manager for CCPA
 *
 * Manages CCPA-specific consumer rights including do not sell controls,
 * consumer request handling, and disclosure reporting.
 */
class ConsumerRightsManager
{
    /** @var DataClassifier Data classifier instance */
    private DataClassifier $dataClassifier;

    /** @var array Do not sell opt-out records indexed by consumer ID */
    private array $doNotSellRecords = [];

    /** @var array Request log for all consumer requests */
    private array $requestLog = [];

    /** @var array Disclosure records for tracking and reporting */
    private array $disclosureRecords = [];

    /**
     * Constructor
     *
     * @param DataClassifier $dataClassifier Data classifier instance
     */
    public function __construct(DataClassifier $dataClassifier)
    {
        $this->dataClassifier = $dataClassifier;
    }

    /**
     * Records a consumer's Do Not Sell opt-out preference
     *
     * @param int $consumerId Consumer identifier
     * @param array $metadata Additional metadata about the opt-out
     * @return array Opt-out record
     */
    public function recordDoNotSellOptOut(int $consumerId, array $metadata = []): array
    {
        $optOutRecord = [
            'consumer_id' => $consumerId,
            'opted_out_at' => date('Y-m-d H:i:s'),
            'source' => $metadata['source'] ?? 'website',
            'user_agent' => $metadata['user_agent'] ?? '',
            'ip_address' => $metadata['ip_address'] ?? '',
            'status' => 'active',
            'history' => [
                [
                    'action' => 'opted_out',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'details' => 'Initial opt-out recorded',
                ],
            ],
        ];

        $this->doNotSellRecords[$consumerId] = $optOutRecord;

        // In a real implementation, this would persist to storage

        return $optOutRecord;
    }

    /**
     * Cancels a consumer's Do Not Sell opt-out preference
     *
     * @param int $consumerId Consumer identifier
     * @param array $metadata Additional metadata about the cancellation
     * @return bool Success indicator
     */
    public function cancelDoNotSellOptOut(int $consumerId, array $metadata = []): bool
    {
        // Check if consumer has an active opt-out record
        if (!isset($this->doNotSellRecords[$consumerId])) {
            return false;
        }

        $record = &$this->doNotSellRecords[$consumerId];

        // Update status and add history entry
        $record['status'] = 'cancelled';
        $record['cancelled_at'] = date('Y-m-d H:i:s');
        $record['cancellation_source'] = $metadata['source'] ?? 'website';
        $record['history'][] = [
            'action' => 'cancelled',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => 'Opt-out cancelled by consumer',
        ];

        return true;
    }

    /**
     * Checks if a consumer has an active Do Not Sell opt-out
     *
     * @param int $consumerId Consumer identifier
     * @return bool Whether consumer has opted out
     */
    public function hasDoNotSellOptOut(int $consumerId): bool
    {
        return isset($this->doNotSellRecords[$consumerId]) &&
               $this->doNotSellRecords[$consumerId]['status'] === 'active';
    }

    /**
     * Processes a consumer data access request
     *
     * @param int $consumerId Consumer identifier
     * @param array $requestMetadata Request metadata
     * @return array Access request processing result
     */
    public function processAccessRequest(int $consumerId, array $requestMetadata): array
    {
        // Log the request
        $requestId = $this->logRequest($consumerId, 'access', $requestMetadata);

        // Gather all data related to the consumer
        $consumerData = $this->gatherConsumerData($consumerId);

        // Classify the data to ensure proper handling
        $classifiedData = [];
        foreach ($consumerData as $source => $data) {
            $classifications = $this->dataClassifier->classifyData($data, 'ccpa_access_request');
            $classifiedData[$source] = [
                'data' => $data,
                'metadata' => $this->dataClassifier->tagData($data, $classifications)['metadata'],
            ];
        }

        // Update request status
        $this->updateRequestStatus($requestId, 'completed', [
            'data_sources' => array_keys($consumerData),
            'completion_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'request_id' => $requestId,
            'consumer_id' => $consumerId,
            'status' => 'completed',
            'data' => $classifiedData,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Processes a data deletion request under CCPA
     *
     * @param int $consumerId Consumer identifier
     * @param array $requestMetadata Request metadata
     * @return array Deletion request processing result
     */
    public function processDeletionRequest(int $consumerId, array $requestMetadata): array
    {
        // Log the request
        $requestId = $this->logRequest($consumerId, 'deletion', $requestMetadata);

        // Identify data sources to delete from
        $dataSources = $this->identifyConsumerDataSources($consumerId);

        // Track deletion results by source
        $deletionResults = [];
        $failedSources = [];

        // Process deletion for each source
        foreach ($dataSources as $source) {
            try {
                $result = $this->deleteFromSource($consumerId, $source);
                $deletionResults[$source] = $result;

                if (!$result['success']) {
                    $failedSources[] = $source;
                }
            } catch (\Exception $e) {
                $deletionResults[$source] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failedSources[] = $source;
            }
        }

        // Update request status
        $status = empty($failedSources) ? 'completed' : 'partially_completed';
        $this->updateRequestStatus($requestId, $status, [
            'processed_sources' => $dataSources,
            'failed_sources' => $failedSources,
            'completion_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'request_id' => $requestId,
            'consumer_id' => $consumerId,
            'status' => $status,
            'deletion_results' => $deletionResults,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Records data disclosure to a third party
     *
     * @param int $consumerId Consumer identifier (0 for anonymous/aggregated)
     * @param string $thirdPartyName Name of the third party
     * @param string $purpose Purpose of the disclosure
     * @param array $dataCategories Categories of data disclosed
     * @param array $metadata Additional metadata
     * @return string Disclosure record identifier
     */
    public function recordDisclosure(
        int $consumerId,
        string $thirdPartyName,
        string $purpose,
        array $dataCategories,
        array $metadata = []
    ): string {
        $disclosureId = uniqid('disc_', true);

        $disclosureRecord = [
            'id' => $disclosureId,
            'consumer_id' => $consumerId,
            'third_party' => $thirdPartyName,
            'purpose' => $purpose,
            'data_categories' => $dataCategories,
            'disclosed_at' => date('Y-m-d H:i:s'),
            'third_party_type' => $metadata['third_party_type'] ?? 'service_provider',
            'business_relationship' => $metadata['business_relationship'] ?? 'processor',
            'is_sale' => $metadata['is_sale'] ?? false,
            'is_service_provider' => $metadata['is_service_provider'] ?? true,
            'disclosure_channel' => $metadata['disclosure_channel'] ?? 'api',
            'record_created_at' => date('Y-m-d H:i:s'),
        ];

        $this->disclosureRecords[$disclosureId] = $disclosureRecord;

        // In a real implementation, this would persist to storage

        return $disclosureId;
    }

    /**
     * Generates a disclosure report for compliance purposes
     *
     * @param string $startDate Start date for report period (YYYY-MM-DD)
     * @param string $endDate End date for report period (YYYY-MM-DD)
     * @param string $format Report format (summary, detailed)
     * @return array Generated report
     */
    public function generateDisclosureReport(string $startDate, string $endDate, string $format = 'summary'): array
    {
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate) + 86399; // Include end date fully (23:59:59)

        // Filter relevant disclosures
        $relevantDisclosures = array_filter(
            $this->disclosureRecords,
            function ($record) use ($startTimestamp, $endTimestamp) {
                $recordTimestamp = strtotime($record['disclosed_at']);
                return $recordTimestamp >= $startTimestamp && $recordTimestamp <= $endTimestamp;
            }
        );

        // Generate appropriate report format
        if ($format === 'summary') {
            return $this->generateSummaryReport($relevantDisclosures, $startDate, $endDate);
        } else {
            return [
                'report_type' => 'detailed',
                'period_start' => $startDate,
                'period_end' => $endDate,
                'generated_at' => date('Y-m-d H:i:s'),
                'total_disclosures' => count($relevantDisclosures),
                'disclosures' => $relevantDisclosures,
            ];
        }
    }

    /**
     * Generates a summary disclosure report
     *
     * @param array $disclosures Relevant disclosure records
     * @param string $startDate Report start date
     * @param string $endDate Report end date
     * @return array Summary report
     */
    private function generateSummaryReport(array $disclosures, string $startDate, string $endDate): array
    {
        // Initialize counters and aggregators
        $totalDisclosures = count($disclosures);
        $totalConsumers = 0;
        $consumersAffected = [];
        $thirdPartyBreakdown = [];
        $categoryBreakdown = [];
        $purposeBreakdown = [];
        $salesCount = 0;

        // Process each disclosure
        foreach ($disclosures as $disclosure) {
            // Count unique consumers
            if ($disclosure['consumer_id'] > 0 && !in_array($disclosure['consumer_id'], $consumersAffected)) {
                $consumersAffected[] = $disclosure['consumer_id'];
            }

            // Count sales
            if ($disclosure['is_sale']) {
                $salesCount++;
            }

            // Third party breakdown
            $thirdPartyName = $disclosure['third_party'];
            if (!isset($thirdPartyBreakdown[$thirdPartyName])) {
                $thirdPartyBreakdown[$thirdPartyName] = 0;
            }
            $thirdPartyBreakdown[$thirdPartyName]++;

            // Purpose breakdown
            $purpose = $disclosure['purpose'];
            if (!isset($purposeBreakdown[$purpose])) {
                $purposeBreakdown[$purpose] = 0;
            }
            $purposeBreakdown[$purpose]++;

            // Category breakdown
            foreach ($disclosure['data_categories'] as $category) {
                if (!isset($categoryBreakdown[$category])) {
                    $categoryBreakdown[$category] = 0;
                }
                $categoryBreakdown[$category]++;
            }
        }

        // Sort breakdown arrays by count (descending)
        arsort($thirdPartyBreakdown);
        arsort($purposeBreakdown);
        arsort($categoryBreakdown);

        return [
            'report_type' => 'summary',
            'period_start' => $startDate,
            'period_end' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'total_disclosures' => $totalDisclosures,
            'unique_consumers_affected' => count($consumersAffected),
            'sales_count' => $salesCount,
            'third_party_breakdown' => $thirdPartyBreakdown,
            'purpose_breakdown' => $purposeBreakdown,
            'category_breakdown' => $categoryBreakdown,
        ];
    }

    /**
     * Logs a consumer rights request
     *
     * @param int $consumerId Consumer identifier
     * @param string $requestType Request type (access, deletion)
     * @param array $metadata Request metadata
     * @return string Request identifier
     */
    private function logRequest(int $consumerId, string $requestType, array $metadata): string
    {
        $requestId = uniqid("ccpa_", true);

        $this->requestLog[$requestId] = [
            'request_id' => $requestId,
            'consumer_id' => $consumerId,
            'type' => $requestType,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'metadata' => $metadata,
            'updates' => [],
            'verification_status' => $metadata['verification_status'] ?? 'pending',
            'verification_method' => $metadata['verification_method'] ?? 'unspecified',
        ];

        return $requestId;
    }

    /**
     * Updates the status of a consumer rights request
     *
     * @param string $requestId Request identifier
     * @param string $status New status
     * @param array $details Additional status details
     * @return void
     */
    private function updateRequestStatus(string $requestId, string $status, array $details = []): void
    {
        if (!isset($this->requestLog[$requestId])) {
            throw new \InvalidArgumentException("Request ID not found: $requestId");
        }

        $this->requestLog[$requestId]['status'] = $status;
        $this->requestLog[$requestId]['updates'][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Gathers all data related to a consumer
     *
     * @param int $consumerId Consumer identifier
     * @return array Consumer data by source
     */
    private function gatherConsumerData(int $consumerId): array
    {
        // This would connect to various data sources to gather all data
        // related to the consumer. This is a simplified version.
        $sources = $this->identifyConsumerDataSources($consumerId);
        $allData = [];

        foreach ($sources as $source) {
            $allData[$source] = $this->getDataFromSource($consumerId, $source);
        }

        return $allData;
    }

    /**
     * Identifies all data sources containing information about a consumer
     *
     * @param int $consumerId Consumer identifier
     * @return array Data source identifiers
     */
    private function identifyConsumerDataSources(int $consumerId): array
    {
        // This would query a data map or catalog to find all sources
        // For demonstration, we return sample sources
        return [
            'user_accounts',
            'customer_profiles',
            'orders',
            'communications',
            'preferences',
            'analytics',
        ];
    }

    /**
     * Retrieves data for a consumer from a specific source
     *
     * @param int $consumerId Consumer identifier
     * @param string $source Data source identifier
     * @return array Consumer data from the source
     */
    private function getDataFromSource(int $consumerId, string $source): array
    {
        // This would query the specific data source
        // Mock data for demonstration
        $mockData = [
            'user_accounts' => [
                'id' => $consumerId,
                'email' => "consumer{$consumerId}@example.com",
                'username' => "consumer{$consumerId}",
                'created_at' => '2023-01-15',
                'status' => 'active',
            ],
            'customer_profiles' => [
                'user_id' => $consumerId,
                'first_name' => 'Sample',
                'last_name' => 'Consumer',
                'phone' => '+1234567890',
                'address' => [
                    'street' => '123 Main St',
                    'city' => 'Los Angeles',
                    'state' => 'CA',
                    'zip' => '90001',
                    'country' => 'USA',
                ],
            ],
            'orders' => [
                [
                    'order_id' => "ORD-{$consumerId}-1",
                    'date' => '2023-02-10',
                    'items' => ['Product A', 'Product B'],
                    'total' => 125.50,
                ],
                [
                    'order_id' => "ORD-{$consumerId}-2",
                    'date' => '2023-03-15',
                    'items' => ['Product C'],
                    'total' => 75.25,
                ],
            ],
        ];

        return $mockData[$source] ?? [];
    }

    /**
     * Deletes consumer data from a specific source
     *
     * @param int $consumerId Consumer identifier
     * @param string $source Data source identifier
     * @return array Deletion result
     */
    private function deleteFromSource(int $consumerId, string $source): array
    {
        // This would execute deletion in the specific data source
        // For demonstration, we return a success response

        return [
            'success' => true,
            'source' => $source,
            'deleted_at' => date('Y-m-d H:i:s'),
            'details' => "Deleted all data for consumer $consumerId from $source",
        ];
    }

    /**
     * Gets the request log
     *
     * @return array Request log
     */
    public function getRequestLog(): array
    {
        return $this->requestLog;
    }

    /**
     * Gets details of a specific request
     *
     * @param string $requestId Request identifier
     * @return array|null Request details or null if not found
     */
    public function getRequestDetails(string $requestId): ?array
    {
        return $this->requestLog[$requestId] ?? null;
    }

    /**
     * Gets all disclosure records
     *
     * @return array Disclosure records
     */
    public function getAllDisclosures(): array
    {
        return $this->disclosureRecords;
    }

    /**
     * Gets disclosure records for a specific consumer
     *
     * @param int $consumerId Consumer identifier
     * @return array Consumer's disclosure records
     */
    public function getConsumerDisclosures(int $consumerId): array
    {
        return array_filter($this->disclosureRecords, function ($record) use ($consumerId) {
            return $record['consumer_id'] === $consumerId;
        });
    }

    /**
     * Gets details of the Do Not Sell opt-out for a consumer
     *
     * @param int $consumerId Consumer identifier
     * @return array|null Opt-out details or null if not found
     */
    public function getDoNotSellRecord(int $consumerId): ?array
    {
        return $this->doNotSellRecords[$consumerId] ?? null;
    }
}
