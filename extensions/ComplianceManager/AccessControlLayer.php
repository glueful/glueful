<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager;

/**
 * Access Control Layer
 *
 * Implements purpose-limited data access, consent-based access controls,
 * and access logging with justification tracking.
 */
class AccessControlLayer
{
    /** @var DataClassifier The data classifier instance */
    private DataClassifier $dataClassifier;

    /** @var array Access control policies */
    private array $accessPolicies = [];

    /** @var array User consent records */
    private array $consentRecords = [];

    /** @var array Access logs */
    private array $accessLogs = [];

    /**
     * Constructor
     *
     * @param DataClassifier $dataClassifier Instance of the data classifier
     */
    public function __construct(DataClassifier $dataClassifier)
    {
        $this->dataClassifier = $dataClassifier;
    }

    /**
     * Validates access to data based on purpose, consent, and authorization
     *
     * @param mixed $data The data being accessed
     * @param string $purpose The purpose for accessing the data
     * @param array $userContext Information about the requesting user and context
     * @return array Result with access decision and modified data if needed
     */
    public function validateAccess($data, string $purpose, array $userContext): array
    {
        // Classify the data first to identify sensitive information
        $classifications = $this->dataClassifier->classifyData($data);

        // If no sensitive data found, grant access
        if (empty($classifications)) {
            $this->logAccess(true, $purpose, $userContext, 'No sensitive data', $classifications);
            return [
                'granted' => true,
                'data' => $data,
                'message' => 'Access granted - no sensitive data found'
            ];
        }

        // Check purpose limitation
        $purposeCheck = $this->checkPurposeLimitation($purpose, $classifications, $userContext);
        if (!$purposeCheck['allowed']) {
            $this->logAccess(false, $purpose, $userContext, $purposeCheck['reason'], $classifications);
            return [
                'granted' => false,
                'data' => null,
                'message' => $purposeCheck['reason']
            ];
        }

        // Check user consent
        $consentCheck = $this->checkConsent($userContext['user_id'] ?? 0, $purpose, $classifications);
        if (!$consentCheck['allowed']) {
            $this->logAccess(false, $purpose, $userContext, $consentCheck['reason'], $classifications);
            return [
                'granted' => false,
                'data' => null,
                'message' => $consentCheck['reason']
            ];
        }

        // Check if user has appropriate authorization
        $authCheck = $this->checkAuthorization($userContext, $classifications);
        if (!$authCheck['allowed']) {
            $this->logAccess(false, $purpose, $userContext, $authCheck['reason'], $classifications);
            return [
                'granted' => false,
                'data' => null,
                'message' => $authCheck['reason']
            ];
        }

        // All checks passed, grant access
        $this->logAccess(true, $purpose, $userContext, 'All validation checks passed', $classifications);

        // Apply data minimization if needed
        $minimizedData = $this->applyDataMinimization($data, $classifications, $purpose, $userContext);

        return [
            'granted' => true,
            'data' => $minimizedData,
            'message' => 'Access granted with appropriate controls',
            'sensitivity' => $this->dataClassifier->tagData($data, $classifications)['metadata']['sensitivity_level']
        ];
    }

    /**
     * Checks if the purpose for accessing data is allowed based on policies
     *
     * @param string $purpose The purpose for accessing the data
     * @param array $classifications Data classifications
     * @param array $userContext User and request context
     * @return array Result with allowed status and reason
     */
    private function checkPurposeLimitation(string $purpose, array $classifications, array $userContext): array
    {
        // Get data types from classifications
        $dataTypes = [];
        foreach ($classifications as $classification) {
            $dataTypes[] = $classification['category'] . '.' . $classification['type'];
        }

        // Check if purpose is defined for all data types
        foreach ($dataTypes as $dataType) {
            $policy = $this->findPolicyForDataType($dataType);

            if (!$policy) {
                return [
                    'allowed' => false,
                    'reason' => "No access policy defined for data type: $dataType"
                ];
            }

            if (!in_array($purpose, $policy['allowed_purposes'])) {
                return [
                    'allowed' => false,
                    'reason' => "Purpose '$purpose' not allowed for data type: $dataType"
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => "Purpose limitation validated for: $purpose"
        ];
    }

    /**
     * Finds the policy applicable to a data type
     *
     * @param string $dataType The data type
     * @return array|null The policy or null if not found
     */
    private function findPolicyForDataType(string $dataType): ?array
    {
        foreach ($this->accessPolicies as $policy) {
            if (
                in_array($dataType, $policy['data_types']) ||
                in_array('*', $policy['data_types'])
            ) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * Checks if user has given consent for accessing data for the specified purpose
     *
     * @param int $userId User identifier
     * @param string $purpose Purpose for accessing data
     * @param array $classifications Data classifications
     * @return array Result with allowed status and reason
     */
    private function checkConsent(int $userId, string $purpose, array $classifications): array
    {
        if ($userId === 0) {
            return [
                'allowed' => false,
                'reason' => 'No user identified for consent validation'
            ];
        }

        // Check if we have consent records for this user
        if (!isset($this->consentRecords[$userId])) {
            return [
                'allowed' => false,
                'reason' => 'No consent records found for user'
            ];
        }

        $userConsent = $this->consentRecords[$userId];

        // Check consent for each data classification
        foreach ($classifications as $classification) {
            $dataType = $classification['category'] . '.' . $classification['type'];

            // If user has not consented to this data type
            if (!isset($userConsent['consented_data_types'][$dataType])) {
                return [
                    'allowed' => false,
                    'reason' => "User has not consented to use of $dataType"
                ];
            }

            // If user has not consented to this purpose
            if (!in_array($purpose, $userConsent['consented_purposes'])) {
                return [
                    'allowed' => false,
                    'reason' => "User has not consented to $purpose purpose"
                ];
            }

            // Check if consent has expired
            if (isset($userConsent['expiry']) && time() > strtotime($userConsent['expiry'])) {
                return [
                    'allowed' => false,
                    'reason' => 'User consent has expired'
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => 'Valid consent verified'
        ];
    }

    /**
     * Checks if user has authorization to access the data
     *
     * @param array $userContext User and request context
     * @param array $classifications Data classifications
     * @return array Result with allowed status and reason
     */
    private function checkAuthorization(array $userContext, array $classifications): array
    {
        // Extract roles and permissions from user context
        $roles = $userContext['roles'] ?? [];
        $permissions = $userContext['permissions'] ?? [];

        // For each classification, check if user has required permissions
        foreach ($classifications as $classification) {
            $dataType = $classification['category'] . '.' . $classification['type'];
            $requiredPermission = "access.$dataType";

            // Check if user has the specific permission
            if (
                !in_array($requiredPermission, $permissions) &&
                !in_array('access.*', $permissions)
            ) {
                // Check if any of user's roles grant this permission
                $roleHasPermission = false;
                foreach ($roles as $role) {
                    $rolePermissions = $this->getRolePermissions($role);
                    if (
                        in_array($requiredPermission, $rolePermissions) ||
                        in_array('access.*', $rolePermissions)
                    ) {
                        $roleHasPermission = true;
                        break;
                    }
                }

                if (!$roleHasPermission) {
                    return [
                        'allowed' => false,
                        'reason' => "User lacks permission to access $dataType"
                    ];
                }
            }
        }

        return [
            'allowed' => true,
            'reason' => 'User has proper authorization'
        ];
    }

    /**
     * Retrieves permissions for a specified role
     *
     * @param string $role Role name
     * @return array Permissions for the role
     */
    private function getRolePermissions(string $role): array
    {
        // This would typically query the role permissions from a database or config
        // For now, we'll use a simple mapping
        $rolePermissions = [
            'admin' => ['access.*'],
            'data_processor' => ['access.PII.*', 'access.Financial.*'],
            'healthcare_provider' => ['access.PHI.*', 'access.PII.*'],
            'support' => ['access.PII.email', 'access.PII.name', 'access.PII.phone'],
        ];

        return $rolePermissions[$role] ?? [];
    }

    /**
     * Applies data minimization based on purpose and classification
     *
     * @param mixed $data The data to be minimized
     * @param array $classifications Data classifications
     * @param string $purpose The access purpose
     * @param array $userContext User context
     * @return mixed Minimized data
     */
    private function applyDataMinimization($data, array $classifications, string $purpose, array $userContext): mixed
    {
        // For array or object data, we can selectively filter
        if (is_array($data) || is_object($data)) {
            $dataArray = is_object($data) ? get_object_vars($data) : $data;
            $minimizedData = [];

            foreach ($dataArray as $key => $value) {
                // Check if this specific field should be minimized
                $shouldInclude = $this->shouldIncludeField($key, $purpose, $userContext, $classifications);

                if ($shouldInclude) {
                    // Recursively minimize nested structures
                    if (is_array($value) || is_object($value)) {
                        $minimizedData[$key] = $this->applyDataMinimization(
                            $value,
                            $classifications,
                            $purpose,
                            $userContext
                        );
                    } else {
                        $minimizedData[$key] = $value;
                    }
                }
                // Field is excluded for this purpose/user combination
            }

            return is_object($data) ? (object)$minimizedData : $minimizedData;
        }

        // For scalar values, return as is (already validated by earlier checks)
        return $data;
    }

    /**
     * Determines if a specific field should be included based on purpose and user context
     *
     * @param string $fieldName Field name to check
     * @param string $purpose Access purpose
     * @param array $userContext User context
     * @param array $classifications Data classifications
     * @return bool Whether to include the field
     */
    private function shouldIncludeField(
        string $fieldName,
        string $purpose,
        array $userContext,
        array $classifications
    ): bool {
        // By default, include fields unless there's a reason not to
        $include = true;

        // Check for specific field in classifications
        foreach ($classifications as $classification) {
            if (strpos($classification['path'], $fieldName) !== false) {
                // This is a classified field, check purpose limitations
                $dataType = $classification['category'] . '.' . $classification['type'];
                $policy = $this->findPolicyForDataType($dataType);

                if ($policy) {
                    // Check if purpose allows this specific field
                    $purposeConfig = $policy['purpose_config'][$purpose] ?? null;

                    if (
                        $purposeConfig &&
                        isset($purposeConfig['excluded_fields']) &&
                        in_array($fieldName, $purposeConfig['excluded_fields'])
                    ) {
                        $include = false;
                        break;
                    }
                }
            }
        }

        return $include;
    }

    /**
     * Logs data access attempts
     *
     * @param bool $granted Whether access was granted
     * @param string $purpose Access purpose
     * @param array $userContext User context
     * @param string $reason Reason for decision
     * @param array $classifications Data classifications
     */
    private function logAccess(
        bool $granted,
        string $purpose,
        array $userContext,
        string $reason,
        array $classifications
    ): void {
        $this->accessLogs[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userContext['user_id'] ?? 0,
            'user_ip' => $userContext['ip'] ?? '',
            'user_agent' => $userContext['user_agent'] ?? '',
            'purpose' => $purpose,
            'granted' => $granted,
            'reason' => $reason,
            'data_types' => array_map(function ($c) {
                return $c['category'] . '.' . $c['type'];
            }, $classifications),
            'justification' => $userContext['justification'] ?? '',
            'request_id' => $userContext['request_id'] ?? uniqid(),
            'sensitivity_level' => empty($classifications) ? 'none' :
                $this->dataClassifier->tagData(null, $classifications)['metadata']['sensitivity_level'],
        ];

        // In a real implementation, this would write to a secure audit log
    }

    /**
     * Registers an access policy
     *
     * @param array $policy The access policy to register
     * @return void
     */
    public function registerAccessPolicy(array $policy): void
    {
        // Validate required policy elements
        if (!isset($policy['policy_id'], $policy['data_types'], $policy['allowed_purposes'])) {
            throw new \InvalidArgumentException('Invalid policy structure');
        }

        $this->accessPolicies[$policy['policy_id']] = $policy;
    }

    /**
     * Records user consent for specific data types and purposes
     *
     * @param int $userId User identifier
     * @param array $dataTypes Array of data types consented to
     * @param array $purposes Array of purposes consented to
     * @param string|null $expiry Optional expiry date for consent
     * @return void
     */
    public function recordConsent(int $userId, array $dataTypes, array $purposes, ?string $expiry = null): void
    {
        $consentedDataTypes = [];
        foreach ($dataTypes as $dataType) {
            $consentedDataTypes[$dataType] = [
                'consented_at' => date('Y-m-d H:i:s'),
            ];
        }

        $this->consentRecords[$userId] = [
            'user_id' => $userId,
            'consented_data_types' => $consentedDataTypes,
            'consented_purposes' => $purposes,
            'recorded_at' => date('Y-m-d H:i:s'),
            'expiry' => $expiry,
        ];

        // In a real implementation, this would persist to storage
    }

    /**
     * Gets the access logs
     *
     * @return array Access logs
     */
    public function getAccessLogs(): array
    {
        return $this->accessLogs;
    }

    /**
     * Gets the consent record for a user
     *
     * @param int $userId User identifier
     * @return array|null User consent record or null if not found
     */
    public function getUserConsent(int $userId): ?array
    {
        return $this->consentRecords[$userId] ?? null;
    }

    /**
     * Checks if user has provided justification for accessing sensitive data
     *
     * @param array $userContext User context including justification
     * @param array $classifications Data classifications
     * @return bool Whether justification is required and valid
     */
    public function validateJustification(array $userContext, array $classifications): bool
    {
        // Determine if justification is needed based on data sensitivity
        $needsJustification = false;

        foreach ($classifications as $classification) {
            if (
                $classification['category'] === 'PHI' ||
                ($classification['category'] === 'PII' && in_array($classification['type'], ['ssn', 'passport', 'dob']))
            ) {
                $needsJustification = true;
                break;
            }
        }

        // If justification is needed, verify it was provided
        if ($needsJustification) {
            $justification = $userContext['justification'] ?? '';
            return !empty($justification) && strlen($justification) >= 10;
        }

        return true; // Justification not needed
    }
}
