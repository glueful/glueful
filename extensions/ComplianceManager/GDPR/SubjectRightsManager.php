<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager\GDPR;

use Glueful\ComplianceManager\DataClassifier;

/**
 * Subject Rights Manager for GDPR
 *
 * Manages GDPR data subject rights including right to access,
 * right to be forgotten, data portability, and consent withdrawal.
 */
class SubjectRightsManager
{
    /** @var DataClassifier Data classifier instance */
    private DataClassifier $dataClassifier;

    /** @var array Request log of all subject rights requests */
    private array $requestLog = [];

    /** @var array Cache of previously processed data */
    private array $dataCache = [];

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
     * Processes a data access request
     *
     * @param int $subjectId Subject identifier
     * @param array $requestMetadata Request metadata
     * @return array Access request processing result
     */
    public function processAccessRequest(int $subjectId, array $requestMetadata): array
    {
        // Log the request
        $requestId = $this->logRequest($subjectId, 'access', $requestMetadata);

        // Gather all data related to the subject
        $subjectData = $this->gatherSubjectData($subjectId);

        // Classify the data to ensure proper handling
        $classifiedData = [];
        foreach ($subjectData as $source => $data) {
            $classifications = $this->dataClassifier->classifyData($data, 'data_access_request');
            $classifiedData[$source] = [
                'data' => $data,
                'metadata' => $this->dataClassifier->tagData($data, $classifications)['metadata'],
            ];
        }

        // Cache the result for potential future use
        $this->dataCache[$requestId] = $classifiedData;

        // Update request status
        $this->updateRequestStatus($requestId, 'completed', [
            'data_sources' => array_keys($subjectData),
            'completion_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'request_id' => $requestId,
            'subject_id' => $subjectId,
            'status' => 'completed',
            'data' => $classifiedData,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Processes a data deletion request (right to be forgotten)
     *
     * @param int $subjectId Subject identifier
     * @param array $requestMetadata Request metadata
     * @return array Deletion request processing result
     */
    public function processDeletionRequest(int $subjectId, array $requestMetadata): array
    {
        // Log the request
        $requestId = $this->logRequest($subjectId, 'deletion', $requestMetadata);

        // Gather all data sources that contain subject data
        $dataSources = $this->identifySubjectDataSources($subjectId);

        // Track deletion results by source
        $deletionResults = [];
        $failedSources = [];

        // Process deletion for each source
        foreach ($dataSources as $source) {
            try {
                $result = $this->deleteFromSource($subjectId, $source);
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
            'subject_id' => $subjectId,
            'status' => $status,
            'deletion_results' => $deletionResults,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Processes a data portability request
     *
     * @param int $subjectId Subject identifier
     * @param array $requestMetadata Request metadata
     * @param string $format Export format (json, xml, csv)
     * @return array Portability request processing result
     */
    public function processPortabilityRequest(int $subjectId, array $requestMetadata, string $format = 'json'): array
    {
        // Log the request
        $requestId = $this->logRequest($subjectId, 'portability', $requestMetadata);

        // Gather all data related to the subject
        $subjectData = $this->gatherSubjectData($subjectId);

        // Convert data to the requested format
        $exportedData = $this->exportData($subjectData, $format);

        // Update request status
        $this->updateRequestStatus($requestId, 'completed', [
            'format' => $format,
            'data_sources' => array_keys($subjectData),
            'completion_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'request_id' => $requestId,
            'subject_id' => $subjectId,
            'status' => 'completed',
            'format' => $format,
            'data' => $exportedData,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Processes a consent withdrawal request
     *
     * @param int $subjectId Subject identifier
     * @param array $requestMetadata Request metadata
     * @param array $consentIds Specific consent identifiers to withdraw, or empty for all
     * @return array Consent withdrawal processing result
     */
    public function processConsentWithdrawal(int $subjectId, array $requestMetadata, array $consentIds = []): array
    {
        // Log the request
        $requestId = $this->logRequest($subjectId, 'consent_withdrawal', $requestMetadata);

        // Track withdrawal results
        $withdrawalResults = [];
        $failedConsents = [];

        // Get current consents for the subject
        $currentConsents = $this->getSubjectConsents($subjectId);

        // If no specific consent IDs provided, withdraw all
        $consentsToWithdraw = empty($consentIds) ? array_keys($currentConsents) : $consentIds;

        // Process each consent withdrawal
        foreach ($consentsToWithdraw as $consentId) {
            if (!isset($currentConsents[$consentId])) {
                $withdrawalResults[$consentId] = [
                    'success' => false,
                    'error' => 'Consent not found',
                ];
                $failedConsents[] = $consentId;
                continue;
            }

            try {
                $result = $this->withdrawConsent($subjectId, $consentId);
                $withdrawalResults[$consentId] = $result;

                if (!$result['success']) {
                    $failedConsents[] = $consentId;
                }
            } catch (\Exception $e) {
                $withdrawalResults[$consentId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failedConsents[] = $consentId;
            }
        }

        // Update request status
        $status = empty($failedConsents) ? 'completed' : 'partially_completed';
        $this->updateRequestStatus($requestId, $status, [
            'processed_consents' => $consentsToWithdraw,
            'failed_consents' => $failedConsents,
            'completion_time' => date('Y-m-d H:i:s'),
        ]);

        return [
            'request_id' => $requestId,
            'subject_id' => $subjectId,
            'status' => $status,
            'withdrawal_results' => $withdrawalResults,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Logs a subject rights request
     *
     * @param int $subjectId Subject identifier
     * @param string $requestType Request type (access, deletion, portability, consent_withdrawal)
     * @param array $metadata Request metadata
     * @return string Request identifier
     */
    private function logRequest(int $subjectId, string $requestType, array $metadata): string
    {
        $requestId = uniqid("gdpr_", true);

        $this->requestLog[$requestId] = [
            'request_id' => $requestId,
            'subject_id' => $subjectId,
            'type' => $requestType,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'metadata' => $metadata,
            'updates' => [],
        ];

        return $requestId;
    }

    /**
     * Updates the status of a subject rights request
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

        // In a real implementation, this would persist to storage
    }

    /**
     * Gathers all data related to a subject
     *
     * @param int $subjectId Subject identifier
     * @return array Subject data by source
     */
    private function gatherSubjectData(int $subjectId): array
    {
        // This would connect to various data sources to gather all data
        // related to the subject. This is a simplified version.
        $sources = $this->identifySubjectDataSources($subjectId);
        $allData = [];

        foreach ($sources as $source) {
            $allData[$source] = $this->getDataFromSource($subjectId, $source);
        }

        return $allData;
    }

    /**
     * Identifies all data sources containing information about a subject
     *
     * @param int $subjectId Subject identifier
     * @return array Data source identifiers
     */
    private function identifySubjectDataSources(int $subjectId): array
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
     * Retrieves data for a subject from a specific source
     *
     * @param int $subjectId Subject identifier
     * @param string $source Data source identifier
     * @return array Subject data from the source
     */
    private function getDataFromSource(int $subjectId, string $source): array
    {
        // This would query the specific data source
        // Mock data for demonstration
        $mockData = [
            'user_accounts' => [
                'id' => $subjectId,
                'email' => "user{$subjectId}@example.com",
                'username' => "user{$subjectId}",
                'created_at' => '2023-01-15',
                'status' => 'active',
            ],
            'customer_profiles' => [
                'user_id' => $subjectId,
                'first_name' => 'Sample',
                'last_name' => 'User',
                'phone' => '+1234567890',
                'address' => [
                    'street' => '123 Main St',
                    'city' => 'Anytown',
                    'state' => 'CA',
                    'zip' => '90210',
                    'country' => 'USA',
                ],
            ],
            'orders' => [
                [
                    'order_id' => "ORD-{$subjectId}-1",
                    'date' => '2023-02-10',
                    'items' => ['Product A', 'Product B'],
                    'total' => 125.50,
                ],
                [
                    'order_id' => "ORD-{$subjectId}-2",
                    'date' => '2023-03-15',
                    'items' => ['Product C'],
                    'total' => 75.25,
                ],
            ],
        ];

        return $mockData[$source] ?? [];
    }

    /**
     * Deletes subject data from a specific source
     *
     * @param int $subjectId Subject identifier
     * @param string $source Data source identifier
     * @return array Deletion result
     */
    private function deleteFromSource(int $subjectId, string $source): array
    {
        // This would execute deletion in the specific data source
        // For demonstration, we return a success response

        return [
            'success' => true,
            'source' => $source,
            'deleted_at' => date('Y-m-d H:i:s'),
            'details' => "Deleted all data for subject $subjectId from $source",
        ];
    }

    /**
     * Exports data in the specified format
     *
     * @param array $data Data to export
     * @param string $format Export format
     * @return string|array Exported data
     */
    private function exportData(array $data, string $format): string|array
    {
        switch (strtolower($format)) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);

            case 'xml':
                // A simple XML conversion would go here
                // For demonstration, we use a placeholder
                $xml = "<data>";
                foreach ($data as $source => $sourceData) {
                    $xml .= "<source name=\"$source\">";
                    $xml .= "</source>";
                }
                $xml .= "</data>";
                return $xml;

            case 'csv':
                // A CSV conversion would go here
                // For demonstration, we return a placeholder
                return "data_source,field,value\n";

            default:
                return $data; // Return raw data if format not supported
        }
    }

    /**
     * Gets current consent records for a subject
     *
     * @param int $subjectId Subject identifier
     * @return array Consent records
     */
    private function getSubjectConsents(int $subjectId): array
    {
        // This would query consent records from storage
        // Mock data for demonstration
        return [
            'consent_' . $subjectId . '_1' => [
                'id' => 'consent_' . $subjectId . '_1',
                'subject_id' => $subjectId,
                'purpose' => 'marketing',
                'given_at' => '2023-01-20',
                'expires_at' => '2024-01-20',
                'status' => 'active',
            ],
            'consent_' . $subjectId . '_2' => [
                'id' => 'consent_' . $subjectId . '_2',
                'subject_id' => $subjectId,
                'purpose' => 'analytics',
                'given_at' => '2023-01-20',
                'expires_at' => null,
                'status' => 'active',
            ],
        ];
    }

    /**
     * Withdraws a specific consent
     *
     * @param int $subjectId Subject identifier
     * @param string $consentId Consent identifier
     * @return array Withdrawal result
     */
    private function withdrawConsent(int $subjectId, string $consentId): array
    {
        // This would update consent records in storage
        // For demonstration, we return a success response

        return [
            'success' => true,
            'consent_id' => $consentId,
            'withdrawn_at' => date('Y-m-d H:i:s'),
            'details' => "Consent $consentId withdrawn for subject $subjectId",
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
}
