<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager\HIPAA;

use Glueful\ComplianceManager\DataClassifier;

/**
 * PHI Access Manager for HIPAA
 *
 * Controls and logs access to protected health information (PHI).
 * Implements business associate agreement management, PHI access controls,
 * minimum necessary access enforcement, and security incident handling.
 */
class PhiAccessManager
{
    /** @var DataClassifier Data classifier instance */
    private DataClassifier $dataClassifier;

    /** @var array Business Associate Agreements */
    private array $businessAssociateAgreements = [];

    /** @var array PHI access log entries */
    private array $accessLog = [];

    /** @var array Security incidents */
    private array $securityIncidents = [];

    /** @var array Access policy rules */
    private array $accessPolicies = [];

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
     * Validates and controls access to PHI data
     *
     * @param mixed $data The data being accessed
     * @param array $accessContext Information about the access request
     * @return array Result with access decision and modified data if needed
     */
    public function validatePhiAccess($data, array $accessContext): array
    {
        // Classify the data to identify PHI elements
        $classifications = $this->dataClassifier->classifyData($data, 'hipaa_phi_access');

        // Filter for just PHI classifications
        $phiClassifications = array_filter($classifications, function ($classification) {
            return $classification['category'] === 'PHI';
        });

        // If no PHI found, grant access
        if (empty($phiClassifications)) {
            $this->logAccess(true, $accessContext, 'No PHI data detected', $phiClassifications);
            return [
                'granted' => true,
                'data' => $data,
                'message' => 'Access granted - no PHI detected'
            ];
        }

        // Check if user is authorized for PHI access
        $authorizationCheck = $this->checkPhiAuthorization($accessContext, $phiClassifications);
        if (!$authorizationCheck['authorized']) {
            $this->logAccess(false, $accessContext, $authorizationCheck['reason'], $phiClassifications);

            // Log potential security incident if appropriate
            if ($authorizationCheck['log_incident']) {
                $this->logSecurityIncident(
                    'unauthorized_phi_access_attempt',
                    $accessContext,
                    $authorizationCheck['reason'],
                    $phiClassifications
                );
            }

            return [
                'granted' => false,
                'data' => null,
                'message' => $authorizationCheck['reason']
            ];
        }

        // Check minimum necessary requirement
        $minimumNecessaryCheck = $this->applyMinimumNecessary($data, $accessContext, $phiClassifications);

        // Log the access
        $this->logAccess(
            true,
            $accessContext,
            'Access granted with minimum necessary applied',
            $phiClassifications
        );

        return [
            'granted' => true,
            'data' => $minimumNecessaryCheck['data'],
            'message' => 'PHI access granted with appropriate controls',
            'redacted_fields' => $minimumNecessaryCheck['redacted_fields'],
            'phi_elements' => count($phiClassifications)
        ];
    }

    /**
     * Checks if the requester is authorized to access PHI
     *
     * @param array $accessContext Access context information
     * @param array $phiClassifications PHI classifications in the data
     * @return array Authorization check result
     */
    private function checkPhiAuthorization(array $accessContext, array $phiClassifications): array
    {
        // Extract key information from context
        $userId = $accessContext['user_id'] ?? 0;
        $roles = $accessContext['roles'] ?? [];
        $purpose = $accessContext['purpose'] ?? '';
        $treatmentRelationship = $accessContext['treatment_relationship'] ?? false;

        // Check for required fields
        if ($userId === 0) {
            return [
                'authorized' => false,
                'reason' => 'User identity required for PHI access',
                'log_incident' => true
            ];
        }

        if (empty($purpose)) {
            return [
                'authorized' => false,
                'reason' => 'Access purpose must be specified for PHI access',
                'log_incident' => false
            ];
        }

        // Check if user has HIPAA training
        $hipaaTraining = $this->checkHipaaTraining($userId);
        if (!$hipaaTraining['completed']) {
            return [
                'authorized' => false,
                'reason' => 'User lacks required HIPAA training',
                'log_incident' => false
            ];
        }

        // Check for TPO exception (Treatment, Payment, Operations)
        $isTpoException = $this->isTreatmentPaymentOperations($purpose);

        // If not TPO, need to check for specific authorization
        if (!$isTpoException) {
            // Check for authorization
            $hasAuthorization = $this->checkSpecificAuthorization($userId, $purpose, $phiClassifications);
            if (!$hasAuthorization) {
                return [
                    'authorized' => false,
                    'reason' => 'No authorization for non-TPO PHI access purpose',
                    'log_incident' => false
                ];
            }
        }

        // If treatment, verify treatment relationship exists
        if ($purpose === 'treatment' && !$treatmentRelationship) {
            return [
                'authorized' => false,
                'reason' => 'Treatment relationship required for treatment purpose',
                'log_incident' => false
            ];
        }

        // Check role-based access control
        $roleAuthorized = false;
        $phiRoles = ['physician', 'nurse', 'healthcare_admin', 'medical_staff', 'hipaa_officer'];

        foreach ($roles as $role) {
            if (in_array($role, $phiRoles)) {
                $roleAuthorized = true;
                break;
            }
        }

        if (!$roleAuthorized) {
            return [
                'authorized' => false,
                'reason' => 'User role not authorized for PHI access',
                'log_incident' => true
            ];
        }

        // All checks passed
        return [
            'authorized' => true,
            'reason' => 'All authorization checks passed',
            'log_incident' => false
        ];
    }

    /**
     * Checks if the purpose falls under Treatment, Payment, or Operations exceptions
     *
     * @param string $purpose The access purpose
     * @return bool Whether purpose is TPO
     */
    private function isTreatmentPaymentOperations(string $purpose): bool
    {
        $tpoPurposes = [
            'treatment',
            'payment',
            'healthcare_operations',
            'quality_improvement',
            'billing',
            'insurance_verification',
            'care_coordination',
        ];

        return in_array(strtolower($purpose), $tpoPurposes);
    }

    /**
     * Checks if the user has completed required HIPAA training
     *
     * @param int $userId User identifier
     * @return array Training verification result
     */
    private function checkHipaaTraining(int $userId): array
    {
        // This would check against a training database or records
        // Mock implementation for demonstration
        $mockTrainingData = [
            // User has current training
            1 => [
                'completed' => true,
                'completed_date' => '2024-12-01',
                'expires' => '2025-12-01',
                'training_type' => 'HIPAA Security',
            ],
            // User with expired training
            2 => [
                'completed' => true,
                'completed_date' => '2023-01-15',
                'expires' => '2024-01-15',
                'training_type' => 'HIPAA Security',
            ],
            // User with no training
            3 => [
                'completed' => false,
            ],
        ];

        // If we don't have a record, assume no training
        if (!isset($mockTrainingData[$userId])) {
            return [
                'completed' => false,
                'reason' => 'No HIPAA training record found',
            ];
        }

        $trainingRecord = $mockTrainingData[$userId];

        // Check if training is complete but expired
        if ($trainingRecord['completed'] && isset($trainingRecord['expires'])) {
            $expiryDate = strtotime($trainingRecord['expires']);
            if (time() > $expiryDate) {
                return [
                    'completed' => false,
                    'reason' => 'HIPAA training expired on ' . $trainingRecord['expires'],
                    'last_completed' => $trainingRecord['completed_date'],
                ];
            }
        }

        return $trainingRecord;
    }

    /**
     * Checks for specific authorization for non-TPO access
     *
     * @param int $userId User identifier
     * @param string $purpose Access purpose
     * @param array $phiClassifications PHI classifications
     * @return bool Whether access is authorized
     */
    private function checkSpecificAuthorization(int $userId, string $purpose, array $phiClassifications): bool
    {
        // This would check against authorization records
        // For demonstration, we'll return a simple result

        // Non-TPO purposes that might be authorized
        $authorizedNonTpoPurposes = [
            'research',
            'public_health',
            'law_enforcement',
        ];

        return in_array($purpose, $authorizedNonTpoPurposes);
    }

    /**
     * Applies minimum necessary principle to PHI data
     *
     * @param mixed $data The data to process
     * @param array $accessContext Access context
     * @param array $phiClassifications PHI classifications
     * @return array Result with filtered data and metadata
     */
    private function applyMinimumNecessary($data, array $accessContext, array $phiClassifications): array
    {
        // We can only filter array or object data
        if (!is_array($data) && !is_object($data)) {
            return [
                'data' => $data,
                'redacted_fields' => [],
                'message' => 'Minimum necessary principle not applicable to scalar data',
            ];
        }

        $dataArray = is_object($data) ? get_object_vars($data) : $data;
        $filteredData = [];
        $redactedFields = [];

        // Extract role and purpose from context
        $role = $accessContext['primary_role'] ?? '';
        $purpose = $accessContext['purpose'] ?? '';

        // Get the policy for this role and purpose
        $policy = $this->getAccessPolicy($role, $purpose);

        foreach ($dataArray as $key => $value) {
            // Check if this field contains PHI
            $fieldContainsPhi = false;
            $phiType = null;

            foreach ($phiClassifications as $classification) {
                if (strpos($classification['path'], $key) !== false) {
                    $fieldContainsPhi = true;
                    $phiType = $classification['type'];
                    break;
                }
            }

            // If field contains PHI, check if it's allowed for this role/purpose
            if ($fieldContainsPhi) {
                $fieldAllowed = $this->isPhiFieldAllowed($key, $phiType, $policy);

                if ($fieldAllowed) {
                    // Recursively filter nested structures
                    if (is_array($value) || is_object($value)) {
                        $nestedResult = $this->applyMinimumNecessary($value, $accessContext, $phiClassifications);
                        $filteredData[$key] = $nestedResult['data'];
                        $redactedFields = array_merge($redactedFields, array_map(function ($field) use ($key) {
                            return "$key.$field";
                        }, $nestedResult['redacted_fields']));
                    } else {
                        $filteredData[$key] = $value;
                    }
                } else {
                    $redactedFields[] = $key;
                    // Don't include this field in the filtered data
                }
            } else {
                // Non-PHI field, include as is
                $filteredData[$key] = $value;
            }
        }

        return [
            'data' => is_object($data) ? (object)$filteredData : $filteredData,
            'redacted_fields' => $redactedFields,
            'message' => count($redactedFields) > 0 ?
                'Minimum necessary principle applied, some fields redacted' :
                'Minimum necessary principle applied, all fields allowed',
        ];
    }

    /**
     * Gets access policy for a role and purpose
     *
     * @param string $role User role
     * @param string $purpose Access purpose
     * @return array Access policy
     */
    private function getAccessPolicy(string $role, string $purpose): array
    {
        // Look for exact role+purpose policy
        $policyKey = "{$role}_{$purpose}";

        if (isset($this->accessPolicies[$policyKey])) {
            return $this->accessPolicies[$policyKey];
        }

        // Look for role-only policy
        if (isset($this->accessPolicies[$role])) {
            return $this->accessPolicies[$role];
        }

        // Look for purpose-only policy
        if (isset($this->accessPolicies[$purpose])) {
            return $this->accessPolicies[$purpose];
        }

        // Default to most restrictive policy
        return [
            'allowed_phi_types' => [],
            'allowed_phi_fields' => [],
            'denied_phi_fields' => ['*'], // Deny all by default
        ];
    }

    /**
     * Checks if a PHI field is allowed by policy
     *
     * @param string $field Field name
     * @param string $phiType PHI type
     * @param array $policy Access policy
     * @return bool Whether field is allowed
     */
    private function isPhiFieldAllowed(string $field, string $phiType, array $policy): bool
    {
        // Check explicit denials first
        if (in_array($field, $policy['denied_phi_fields']) || in_array('*', $policy['denied_phi_fields'])) {
            // Check for exception to denial
            if (in_array($field, $policy['allowed_phi_fields'])) {
                return true;
            }
            return false;
        }

        // Check if PHI type is allowed
        if (in_array($phiType, $policy['allowed_phi_types']) || in_array('*', $policy['allowed_phi_types'])) {
            return true;
        }

        // Check if field is explicitly allowed
        if (in_array($field, $policy['allowed_phi_fields']) || in_array('*', $policy['allowed_phi_fields'])) {
            return true;
        }

        // Default to deny
        return false;
    }

    /**
     * Records a Business Associate Agreement
     *
     * @param string $associateName Business associate name
     * @param array $agreementDetails Agreement details
     * @return string Agreement identifier
     */
    public function recordBusinessAssociateAgreement(string $associateName, array $agreementDetails): string
    {
        $agreementId = uniqid('baa_', true);

        $agreement = [
            'id' => $agreementId,
            'associate_name' => $associateName,
            'effective_date' => $agreementDetails['effective_date'] ?? date('Y-m-d'),
            'expiration_date' => $agreementDetails['expiration_date'] ?? null,
            'services_provided' => $agreementDetails['services_provided'] ?? [],
            'phi_access_allowed' => $agreementDetails['phi_access_allowed'] ?? false,
            'phi_access_purpose' => $agreementDetails['phi_access_purpose'] ?? '',
            'phi_types_allowed' => $agreementDetails['phi_types_allowed'] ?? [],
            'signed_by' => $agreementDetails['signed_by'] ?? '',
            'signed_date' => $agreementDetails['signed_date'] ?? date('Y-m-d'),
            'status' => 'active',
            'termination_provisions' => $agreementDetails['termination_provisions'] ?? '',
            'breach_notification_terms' => $agreementDetails['breach_notification_terms'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'last_reviewed' => date('Y-m-d H:i:s'),
        ];

        $this->businessAssociateAgreements[$agreementId] = $agreement;

        // In a real implementation, this would persist to storage

        return $agreementId;
    }

    /**
     * Checks if a Business Associate Agreement is valid
     *
     * @param string $associateName Business associate name
     * @return array Validation result
     */
    public function validateBusinessAssociateAgreement(string $associateName): array
    {
        // Find the agreement for this associate
        $agreement = null;
        foreach ($this->businessAssociateAgreements as $agreementId => $baa) {
            if ($baa['associate_name'] === $associateName) {
                $agreement = $baa;
                break;
            }
        }

        if ($agreement === null) {
            return [
                'valid' => false,
                'reason' => 'No Business Associate Agreement found',
            ];
        }

        // Check if agreement is active
        if ($agreement['status'] !== 'active') {
            return [
                'valid' => false,
                'reason' => 'Business Associate Agreement is not active',
                'status' => $agreement['status'],
            ];
        }

        // Check if agreement has expired
        if ($agreement['expiration_date'] !== null && strtotime($agreement['expiration_date']) < time()) {
            return [
                'valid' => false,
                'reason' => 'Business Associate Agreement has expired',
                'expired_on' => $agreement['expiration_date'],
            ];
        }

        // Agreement is valid
        return [
            'valid' => true,
            'agreement_id' => $agreement['id'],
            'effective_date' => $agreement['effective_date'],
            'phi_access_allowed' => $agreement['phi_access_allowed'],
            'phi_types_allowed' => $agreement['phi_types_allowed'],
        ];
    }

    /**
     * Logs PHI access (whether granted or denied)
     *
     * @param bool $granted Whether access was granted
     * @param array $accessContext Access context
     * @param string $reason Access decision reason
     * @param array $phiClassifications PHI classifications
     * @return string Log entry identifier
     */
    private function logAccess(bool $granted, array $accessContext, string $reason, array $phiClassifications): string
    {
        $logId = uniqid('phi_access_', true);

        $logEntry = [
            'id' => $logId,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $accessContext['user_id'] ?? 0,
            'user_ip' => $accessContext['ip'] ?? '',
            'user_agent' => $accessContext['user_agent'] ?? '',
            'roles' => $accessContext['roles'] ?? [],
            'purpose' => $accessContext['purpose'] ?? '',
            'granted' => $granted,
            'reason' => $reason,
            'phi_elements' => count($phiClassifications),
            'phi_types' => array_values(array_unique(array_map(function ($c) {
                return $c['type'];
            }, $phiClassifications))),
            'patient_id' => $accessContext['patient_id'] ?? 0,
            'treatment_relationship' => $accessContext['treatment_relationship'] ?? false,
            'justification' => $accessContext['justification'] ?? '',
            'request_id' => $accessContext['request_id'] ?? uniqid(),
            'application' => $accessContext['application'] ?? '',
            'workstation_id' => $accessContext['workstation_id'] ?? '',
        ];

        $this->accessLog[] = $logEntry;

        // In a real implementation, this would write to a secure audit log

        return $logId;
    }

    /**
     * Logs a security incident
     *
     * @param string $incidentType Type of incident
     * @param array $context Context information
     * @param string $description Incident description
     * @param array $phiClassifications PHI classifications if applicable
     * @return string Incident identifier
     */
    public function logSecurityIncident(
        string $incidentType,
        array $context,
        string $description,
        array $phiClassifications = []
    ): string {
        $incidentId = uniqid('incident_', true);

        $incident = [
            'id' => $incidentId,
            'type' => $incidentType,
            'timestamp' => date('Y-m-d H:i:s'),
            'description' => $description,
            'user_id' => $context['user_id'] ?? 0,
            'user_ip' => $context['ip'] ?? '',
            'patient_id' => $context['patient_id'] ?? 0,
            'phi_involved' => !empty($phiClassifications),
            'phi_types' => array_values(array_unique(array_map(function ($c) {
                return $c['type'];
            }, $phiClassifications))),
            'status' => 'open',
            'severity' => $this->calculateIncidentSeverity($incidentType, $phiClassifications),
            'breach_determination' => 'pending',
            'action_taken' => '',
            'reported_by' => $context['user_id'] ?? 'system',
        ];

        $this->securityIncidents[$incidentId] = $incident;

        // In a real implementation, this would trigger notifications and workflows

        return $incidentId;
    }

    /**
     * Calculates incident severity based on type and PHI involved
     *
     * @param string $incidentType Incident type
     * @param array $phiClassifications PHI classifications involved
     * @return string Severity level (low, medium, high, critical)
     */
    private function calculateIncidentSeverity(string $incidentType, array $phiClassifications): string
    {
        // High-severity incident types
        $highSeverityTypes = [
            'unauthorized_phi_access',
            'unauthorized_phi_access_attempt',
            'phi_disclosure',
            'hacking',
            'ransomware',
            'malware_affecting_phi',
        ];

        // Medium-severity incident types
        $mediumSeverityTypes = [
            'improper_phi_access',
            'improper_disposal',
            'lost_device',
            'policy_violation',
        ];

        // Determine base severity from incident type
        if (in_array($incidentType, $highSeverityTypes)) {
            $baseSeverity = 'high';
        } elseif (in_array($incidentType, $mediumSeverityTypes)) {
            $baseSeverity = 'medium';
        } else {
            $baseSeverity = 'low';
        }

        // Upgrade severity based on PHI involvement
        if (!empty($phiClassifications)) {
            // Check for sensitive PHI types
            $sensitivePhiTypes = ['medical_record_number', 'health_plan_beneficiary', 'diagnosis'];

            $containsSensitivePhi = false;
            foreach ($phiClassifications as $classification) {
                if (in_array($classification['type'], $sensitivePhiTypes)) {
                    $containsSensitivePhi = true;
                    break;
                }
            }

            // Upgrade severity if sensitive PHI involved
            if ($containsSensitivePhi) {
                if ($baseSeverity === 'high') {
                    return 'critical';
                } elseif ($baseSeverity === 'medium') {
                    return 'high';
                } else {
                    return 'medium';
                }
            }

            // Otherwise, general PHI still increases severity
            if ($baseSeverity === 'low') {
                return 'medium';
            }
        }

        return $baseSeverity;
    }

    /**
     * Updates incident status and details
     *
     * @param string $incidentId Incident identifier
     * @param array $updates Updates to apply
     * @return bool Success indicator
     */
    public function updateSecurityIncident(string $incidentId, array $updates): bool
    {
        if (!isset($this->securityIncidents[$incidentId])) {
            return false;
        }

        foreach ($updates as $key => $value) {
            if (isset($this->securityIncidents[$incidentId][$key]) && $key !== 'id') {
                $this->securityIncidents[$incidentId][$key] = $value;
            }
        }

        $this->securityIncidents[$incidentId]['last_updated'] = date('Y-m-d H:i:s');

        return true;
    }

    /**
     * Registers a PHI access policy
     *
     * @param string $policyKey Policy key (role, purpose, or role_purpose)
     * @param array $policy Policy configuration
     * @return bool Success indicator
     */
    public function registerAccessPolicy(string $policyKey, array $policy): bool
    {
        // Validate required policy elements
        if (
            !isset($policy['allowed_phi_types']) ||
            !isset($policy['allowed_phi_fields']) ||
            !isset($policy['denied_phi_fields'])
        ) {
            return false;
        }

        $this->accessPolicies[$policyKey] = $policy;

        return true;
    }

    /**
     * Get access logs for analysis
     *
     * @param array $filters Optional filters
     * @return array Filtered access logs
     */
    public function getAccessLogs(array $filters = []): array
    {
        // Apply filters if provided
        if (empty($filters)) {
            return $this->accessLog;
        }

        return array_filter($this->accessLog, function ($logEntry) use ($filters) {
            foreach ($filters as $key => $value) {
                if (!isset($logEntry[$key]) || $logEntry[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Get security incidents
     *
     * @param string|null $status Filter by status
     * @return array Security incidents
     */
    public function getSecurityIncidents(?string $status = null): array
    {
        if ($status === null) {
            return $this->securityIncidents;
        }

        return array_filter($this->securityIncidents, function ($incident) use ($status) {
            return $incident['status'] === $status;
        });
    }

    /**
     * Gets details of a specific Business Associate Agreement
     *
     * @param string $agreementId Agreement identifier
     * @return array|null Agreement details or null if not found
     */
    public function getBusinessAssociateAgreement(string $agreementId): ?array
    {
        return $this->businessAssociateAgreements[$agreementId] ?? null;
    }

    /**
     * Gets all Business Associate Agreements
     *
     * @param string|null $status Filter by status
     * @return array Agreement records
     */
    public function getAllBusinessAssociateAgreements(?string $status = null): array
    {
        if ($status === null) {
            return $this->businessAssociateAgreements;
        }

        return array_filter($this->businessAssociateAgreements, function ($agreement) use ($status) {
            return $agreement['status'] === $status;
        });
    }
}
