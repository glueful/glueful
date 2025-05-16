<?php

declare(strict_types=1);

namespace Glueful\ComplianceManager\HIPAA;

/**
 * Business Associate Agreement Manager
 *
 * Manages the lifecycle of Business Associate Agreements (BAAs) for HIPAA compliance.
 * Handles agreement creation, tracking, renewals, and terminations.
 */
class BusinessAssociateManager
{
    /** @var array Business Associate Agreements */
    private array $agreements = [];

    /** @var array Business Associates information */
    private array $associates = [];

    /** @var array Agreement templates */
    private array $templates = [];

    /** @var array Audit log for BAA activities */
    private array $auditLog = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize with default template
        $this->templates['standard'] = [
            'name' => 'Standard BAA Template',
            'version' => '1.0',
            'created_at' => date('Y-m-d H:i:s'),
            'clauses' => [
                'permitted_uses' => 'Business Associate shall not use or disclose Protected Health Information ' .
                    'other than as permitted or required by this Agreement or as Required by Law.',
                'safeguards' => 'Business Associate shall use appropriate safeguards, and comply with Subpart C ' .
                    'of 45 CFR Part 164 with respect to electronic protected health information, to prevent use ' .
                    'or disclosure of protected health information other than as provided for by this Agreement.',
                'reporting' => 'Business Associate shall report to Covered Entity any use or disclosure of ' .
                    'protected health information not provided for by this Agreement of which it becomes aware, ' .
                    'including breaches of unsecured protected health information as required by 45 CFR 164.410.',
                'subcontractors' => 'Business Associate shall ensure that any subcontractors that create, ' .
                    'receive, maintain, or transmit protected health information on behalf of the Business Associate ' .
                    'agree to the same restrictions and conditions that apply to the Business Associate with ' .
                    'respect to such information.',
                'access' => 'Business Associate shall make available protected health information in a designated ' .
                    'record set to Covered Entity as necessary to satisfy Covered Entity\'s obligations under ' .
                    '45 CFR 164.524.',
                'termination' => 'Upon termination of this Agreement for any reason, Business Associate shall ' .
                    'return to Covered Entity or destroy all Protected Health Information received from Covered ' .
                    'Entity, or created, maintained, or received by Business Associate on behalf of Covered Entity, ' .
                    'that the Business Associate still maintains in any form.',
            ],
        ];
    }

    /**
     * Creates a new Business Associate record
     *
     * @param string $name Associate name
     * @param array $details Associate details
     * @return string Associate identifier
     */
    public function createBusinessAssociate(string $name, array $details): string
    {
        $associateId = uniqid('ba_', true);

        $associate = [
            'id' => $associateId,
            'name' => $name,
            'type' => $details['type'] ?? 'vendor',
            'primary_contact' => $details['primary_contact'] ?? '',
            'email' => $details['email'] ?? '',
            'phone' => $details['phone'] ?? '',
            'address' => $details['address'] ?? '',
            'services' => $details['services'] ?? [],
            'phi_access_needed' => $details['phi_access_needed'] ?? false,
            'phi_access_reason' => $details['phi_access_reason'] ?? '',
            'risk_level' => $details['risk_level'] ?? 'medium',
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'notes' => $details['notes'] ?? '',
        ];

        $this->associates[$associateId] = $associate;

        $this->logAuditEvent(
            'associate_created',
            "Created business associate: $name",
            ['associate_id' => $associateId]
        );

        return $associateId;
    }

    /**
     * Creates a Business Associate Agreement
     *
     * @param string $associateId Business associate identifier
     * @param array $agreementDetails Agreement details
     * @param string $templateId Template identifier (optional)
     * @return string Agreement identifier
     */
    public function createAgreement(
        string $associateId,
        array $agreementDetails,
        string $templateId = 'standard'
    ): string {
        // Verify associate exists
        if (!isset($this->associates[$associateId])) {
            throw new \InvalidArgumentException("Business Associate not found: $associateId");
        }

        // Verify template exists
        if (!isset($this->templates[$templateId])) {
            throw new \InvalidArgumentException("Template not found: $templateId");
        }

        $agreementId = uniqid('baa_', true);
        $associateName = $this->associates[$associateId]['name'];

        $agreement = [
            'id' => $agreementId,
            'associate_id' => $associateId,
            'associate_name' => $associateName,
            'template_id' => $templateId,
            'template_version' => $this->templates[$templateId]['version'],
            'effective_date' => $agreementDetails['effective_date'] ?? date('Y-m-d'),
            'expiration_date' => $agreementDetails['expiration_date'] ?? null,
            'renewal_type' => $agreementDetails['renewal_type'] ?? 'manual',
            'services_covered' => $agreementDetails['services_covered'] ?? [],
            'phi_access_allowed' => $agreementDetails['phi_access_allowed'] ?? false,
            'phi_access_purpose' => $agreementDetails['phi_access_purpose'] ?? '',
            'phi_types_allowed' => $agreementDetails['phi_types_allowed'] ?? [],
            'signed_by_covered_entity' => $agreementDetails['signed_by_covered_entity'] ?? '',
            'signed_by_business_associate' => $agreementDetails['signed_by_business_associate'] ?? '',
            'signed_date' => $agreementDetails['signed_date'] ?? date('Y-m-d'),
            'status' => 'draft',
            'custom_terms' => $agreementDetails['custom_terms'] ?? [],
            'created_at' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s'),
            'history' => [
                [
                    'action' => 'created',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'details' => 'Agreement created',
                    'user_id' => $agreementDetails['created_by'] ?? 0,
                ],
            ],
        ];

        $this->agreements[$agreementId] = $agreement;

        $this->logAuditEvent(
            'agreement_created',
            "Created BAA for associate: $associateName",
            [
                'agreement_id' => $agreementId,
                'associate_id' => $associateId,
            ]
        );

        return $agreementId;
    }

    /**
     * Activates a Business Associate Agreement
     *
     * @param string $agreementId Agreement identifier
     * @param array $signatureDetails Signature details
     * @return bool Success indicator
     */
    public function activateAgreement(string $agreementId, array $signatureDetails): bool
    {
        if (!isset($this->agreements[$agreementId])) {
            return false;
        }

        $agreement = &$this->agreements[$agreementId];

        // Update signature information
        if (isset($signatureDetails['signed_by_covered_entity'])) {
            $agreement['signed_by_covered_entity'] = $signatureDetails['signed_by_covered_entity'];
        }

        if (isset($signatureDetails['signed_by_business_associate'])) {
            $agreement['signed_by_business_associate'] = $signatureDetails['signed_by_business_associate'];
        }

        if (isset($signatureDetails['signed_date'])) {
            $agreement['signed_date'] = $signatureDetails['signed_date'];
        }

        // Update status
        $agreement['status'] = 'active';
        $agreement['last_updated'] = date('Y-m-d H:i:s');

        // Add history entry
        $agreement['history'][] = [
            'action' => 'activated',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => 'Agreement activated and signed',
            'user_id' => $signatureDetails['user_id'] ?? 0,
        ];

        $this->logAuditEvent(
            'agreement_activated',
            "Activated BAA for associate: {$agreement['associate_name']}",
            [
                'agreement_id' => $agreementId,
                'associate_id' => $agreement['associate_id'],
            ]
        );

        return true;
    }

    /**
     * Terminates a Business Associate Agreement
     *
     * @param string $agreementId Agreement identifier
     * @param array $terminationDetails Termination details
     * @return bool Success indicator
     */
    public function terminateAgreement(string $agreementId, array $terminationDetails): bool
    {
        if (!isset($this->agreements[$agreementId])) {
            return false;
        }

        $agreement = &$this->agreements[$agreementId];

        // Update status and termination details
        $agreement['status'] = 'terminated';
        $agreement['termination_date'] = $terminationDetails['termination_date'] ?? date('Y-m-d');
        $agreement['termination_reason'] = $terminationDetails['reason'] ?? '';
        $agreement['last_updated'] = date('Y-m-d H:i:s');

        // Add history entry
        $agreement['history'][] = [
            'action' => 'terminated',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => 'Agreement terminated: ' . ($terminationDetails['reason'] ?? 'No reason provided'),
            'user_id' => $terminationDetails['user_id'] ?? 0,
        ];

        $this->logAuditEvent(
            'agreement_terminated',
            "Terminated BAA for associate: {$agreement['associate_name']}",
            [
                'agreement_id' => $agreementId,
                'associate_id' => $agreement['associate_id'],
                'reason' => $terminationDetails['reason'] ?? '',
            ]
        );

        return true;
    }

    /**
     * Renews a Business Associate Agreement
     *
     * @param string $agreementId Agreement identifier
     * @param array $renewalDetails Renewal details
     * @return string New agreement identifier
     */
    public function renewAgreement(string $agreementId, array $renewalDetails): string
    {
        if (!isset($this->agreements[$agreementId])) {
            throw new \InvalidArgumentException("Agreement not found: $agreementId");
        }

        $oldAgreement = $this->agreements[$agreementId];

        // Create a new agreement based on the old one
        $newAgreementDetails = [
            'effective_date' => $renewalDetails['effective_date'] ?? date('Y-m-d'),
            'expiration_date' => $renewalDetails['expiration_date'] ?? null,
            'renewal_type' => $renewalDetails['renewal_type'] ?? $oldAgreement['renewal_type'],
            'services_covered' => $renewalDetails['services_covered'] ?? $oldAgreement['services_covered'],
            'phi_access_allowed' => $renewalDetails['phi_access_allowed'] ?? $oldAgreement['phi_access_allowed'],
            'phi_access_purpose' => $renewalDetails['phi_access_purpose'] ?? $oldAgreement['phi_access_purpose'],
            'phi_types_allowed' => $renewalDetails['phi_types_allowed'] ?? $oldAgreement['phi_types_allowed'],
            'custom_terms' => $renewalDetails['custom_terms'] ?? $oldAgreement['custom_terms'],
            'created_by' => $renewalDetails['user_id'] ?? 0,
            'renewed_from' => $agreementId,
        ];

        // Create the new agreement
        $newAgreementId = $this->createAgreement(
            $oldAgreement['associate_id'],
            $newAgreementDetails,
            $renewalDetails['template_id'] ?? $oldAgreement['template_id']
        );

        // Mark old agreement as superseded
        $this->agreements[$agreementId]['status'] = 'superseded';
        $this->agreements[$agreementId]['superseded_by'] = $newAgreementId;
        $this->agreements[$agreementId]['last_updated'] = date('Y-m-d H:i:s');
        $this->agreements[$agreementId]['history'][] = [
            'action' => 'superseded',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => "Agreement renewed and superseded by agreement: $newAgreementId",
            'user_id' => $renewalDetails['user_id'] ?? 0,
        ];

        $this->logAuditEvent(
            'agreement_renewed',
            "Renewed BAA for associate: {$oldAgreement['associate_name']}",
            [
                'old_agreement_id' => $agreementId,
                'new_agreement_id' => $newAgreementId,
                'associate_id' => $oldAgreement['associate_id'],
            ]
        );

        return $newAgreementId;
    }

    /**
     * Creates a new BAA template
     *
     * @param string $templateName Template name
     * @param array $templateContent Template content
     * @return string Template identifier
     */
    public function createTemplate(string $templateName, array $templateContent): string
    {
        $templateId = strtolower(str_replace(' ', '_', $templateName));

        if (isset($this->templates[$templateId])) {
            $templateId .= '_' . uniqid();
        }

        $template = [
            'id' => $templateId,
            'name' => $templateName,
            'version' => $templateContent['version'] ?? '1.0',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $templateContent['created_by'] ?? 0,
            'clauses' => $templateContent['clauses'] ?? [],
            'status' => 'active',
        ];

        $this->templates[$templateId] = $template;

        $this->logAuditEvent(
            'template_created',
            "Created BAA template: $templateName",
            ['template_id' => $templateId]
        );

        return $templateId;
    }

    /**
     * Checks if a Business Associate has an active agreement
     *
     * @param string $associateId Associate identifier
     * @return array Check result
     */
    public function checkActiveAgreement(string $associateId): array
    {
        // Verify associate exists
        if (!isset($this->associates[$associateId])) {
            return [
                'has_active_agreement' => false,
                'reason' => 'Business Associate not found',
            ];
        }

        // Find active agreements for this associate
        $activeAgreements = [];

        foreach ($this->agreements as $agreementId => $agreement) {
            if ($agreement['associate_id'] === $associateId && $agreement['status'] === 'active') {
                // Check if agreement has expired
                if (
                    $agreement['expiration_date'] !== null &&
                    strtotime($agreement['expiration_date']) < time()
                ) {
                    continue; // Skip expired agreements
                }

                $activeAgreements[$agreementId] = $agreement;
            }
        }

        // Return result based on active agreements found
        if (empty($activeAgreements)) {
            return [
                'has_active_agreement' => false,
                'reason' => 'No active agreement found',
                'associate_name' => $this->associates[$associateId]['name'],
            ];
        }

        // Get the most recent active agreement
        uasort($activeAgreements, function ($a, $b) {
            return strtotime($b['effective_date']) - strtotime($a['effective_date']);
        });

        $latestAgreement = reset($activeAgreements);
        $latestAgreementId = key($activeAgreements);

        return [
            'has_active_agreement' => true,
            'agreement_id' => $latestAgreementId,
            'effective_date' => $latestAgreement['effective_date'],
            'expiration_date' => $latestAgreement['expiration_date'],
            'phi_access_allowed' => $latestAgreement['phi_access_allowed'],
            'associate_name' => $this->associates[$associateId]['name'],
        ];
    }

    /**
     * Gets agreements expiring soon
     *
     * @param int $daysThreshold Number of days to consider "soon"
     * @return array Expiring agreements
     */
    public function getExpiringAgreements(int $daysThreshold = 30): array
    {
        $now = time();
        $threshold = $now + ($daysThreshold * 86400);
        $expiringAgreements = [];

        foreach ($this->agreements as $agreementId => $agreement) {
            // Skip agreements without expiration or non-active
            if ($agreement['status'] !== 'active' || $agreement['expiration_date'] === null) {
                continue;
            }

            $expirationTimestamp = strtotime($agreement['expiration_date']);

            // Check if expiration is within threshold
            if ($expirationTimestamp > $now && $expirationTimestamp <= $threshold) {
                $daysUntilExpiration = floor(($expirationTimestamp - $now) / 86400);

                $expiringAgreements[$agreementId] = [
                    'agreement_id' => $agreementId,
                    'associate_id' => $agreement['associate_id'],
                    'associate_name' => $agreement['associate_name'],
                    'expiration_date' => $agreement['expiration_date'],
                    'days_until_expiration' => $daysUntilExpiration,
                ];
            }
        }

        // Sort by days until expiration (ascending)
        uasort($expiringAgreements, function ($a, $b) {
            return $a['days_until_expiration'] - $b['days_until_expiration'];
        });

        return $expiringAgreements;
    }

    /**
     * Logs an audit event
     *
     * @param string $eventType Event type
     * @param string $description Event description
     * @param array $metadata Additional metadata
     * @return string Event identifier
     */
    private function logAuditEvent(string $eventType, string $description, array $metadata = []): string
    {
        $eventId = uniqid('event_', true);

        $event = [
            'id' => $eventId,
            'type' => $eventType,
            'timestamp' => date('Y-m-d H:i:s'),
            'description' => $description,
            'user_id' => $metadata['user_id'] ?? 0,
            'metadata' => $metadata,
        ];

        $this->auditLog[] = $event;

        // In a real implementation, this would persist to storage

        return $eventId;
    }

    /**
     * Gets details of a specific Business Associate
     *
     * @param string $associateId Associate identifier
     * @return array|null Associate details or null if not found
     */
    public function getBusinessAssociate(string $associateId): ?array
    {
        return $this->associates[$associateId] ?? null;
    }

    /**
     * Gets all Business Associates
     *
     * @param string|null $status Filter by status
     * @return array Associate records
     */
    public function getAllBusinessAssociates(?string $status = null): array
    {
        if ($status === null) {
            return $this->associates;
        }

        return array_filter($this->associates, function ($associate) use ($status) {
            return $associate['status'] === $status;
        });
    }

    /**
     * Gets details of a specific agreement
     *
     * @param string $agreementId Agreement identifier
     * @return array|null Agreement details or null if not found
     */
    public function getAgreement(string $agreementId): ?array
    {
        return $this->agreements[$agreementId] ?? null;
    }

    /**
     * Gets all agreements for a specific Business Associate
     *
     * @param string $associateId Associate identifier
     * @return array Associate's agreements
     */
    public function getAssociateAgreements(string $associateId): array
    {
        return array_filter($this->agreements, function ($agreement) use ($associateId) {
            return $agreement['associate_id'] === $associateId;
        });
    }

    /**
     * Gets a specific template
     *
     * @param string $templateId Template identifier
     * @return array|null Template details or null if not found
     */
    public function getTemplate(string $templateId): ?array
    {
        return $this->templates[$templateId] ?? null;
    }

    /**
     * Gets all templates
     *
     * @return array All templates
     */
    public function getAllTemplates(): array
    {
        return $this->templates;
    }

    /**
     * Gets audit log entries
     *
     * @param array $filters Optional filters
     * @return array Filtered audit log entries
     */
    public function getAuditLog(array $filters = []): array
    {
        // Apply filters if provided
        if (empty($filters)) {
            return $this->auditLog;
        }

        return array_filter($this->auditLog, function ($event) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'metadata') {
                    // Special handling for metadata fields
                    foreach ($value as $metaKey => $metaValue) {
                        if (
                            !isset($event['metadata'][$metaKey]) ||
                            $event['metadata'][$metaKey] !== $metaValue
                        ) {
                            return false;
                        }
                    }
                } else {
                    if (!isset($event[$key]) || $event[$key] !== $value) {
                        return false;
                    }
                }
            }
            return true;
        });
    }
}
